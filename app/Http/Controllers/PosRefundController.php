<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Document;
use App\Models\DocumentLine;
use App\Models\Payment;
use App\Models\Sequence;
use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PosRefundController extends Controller
{
    /**
     * Refund entry screen for a given original order — shows each line
     * with how much of it has already been refunded (across any prior
     * partial refunds) and how much remains available to refund.
     */
    public function create(Request $request, Document $document): Response
    {
        abort_unless($document->type === 'pos_order', 404);
        abort_unless(in_array($document->status, ['confirmed', 'paid']), 422, 'Only completed orders can be refunded.');

        $document->load(['lines.variant.product', 'lines.tax', 'contact', 'location', 'payments']);

        $refundableLines = $document->lines->map(function (DocumentLine $line) {
            $alreadyRefunded = $this->quantityAlreadyRefunded($line);

            return [
                'id' => $line->id,
                'description' => $line->description,
                'variant_id' => $line->variant_id,
                'original_quantity' => (float) $line->quantity,
                'already_refunded' => $alreadyRefunded,
                'refundable_quantity' => round((float) $line->quantity - $alreadyRefunded, 3),
                'unit_price' => $line->unit_price,
                'tax_id' => $line->tax_id,
                'tax_rate' => $line->tax?->rate,
                'tax_type' => $line->tax?->type,
            ];
        })->filter(fn ($line) => $line['refundable_quantity'] > 0)->values();

        return Inertia::render('pos/Refund', [
            'document' => $document,
            'refundableLines' => $refundableLines,
            'totalPaid' => (float) $document->amount_paid,
            'totalAlreadyRefunded' => $this->totalAlreadyRefunded($document),
        ]);
    }

    /**
     * Creates a linked pos_refund document: negative-quantity lines for
     * the returned items, a `return` StockMovement per line to restore
     * stock, and a negative Payment representing money paid back out.
     * The original order is never mutated — refund history is derived by
     * querying documents where source_document_id = the original.
     */
    public function store(Request $request, Document $document)
    {
        abort_unless($document->type === 'pos_order', 404);
        abort_unless(in_array($document->status, ['confirmed', 'paid']), 422, 'Only completed orders can be refunded.');

        $validated = $request->validate([
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.document_line_id' => ['required', 'exists:document_lines,id'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'refund_method' => ['required', 'in:cash,card,bank_transfer,other'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $document->load('lines.tax');

        $refund = DB::transaction(function () use ($document, $validated, $request) {
            $refundDocument = Document::create([
                'type' => 'pos_refund',
                'number' => Sequence::next('pos_refund'),
                'contact_id' => $document->contact_id,
                'location_id' => $document->location_id,
                'user_id' => $request->user()->id,
                'status' => 'refunded',
                'source_document_id' => $document->id,
                'currency' => $document->currency,
                'notes' => $validated['reason'] ?? null,
                'confirmed_at' => now(),
            ]);

            $refundTotal = 0.0;

            foreach ($validated['lines'] as $refundLineInput) {
                $originalLine = $document->lines->firstWhere('id', $refundLineInput['document_line_id']);

                if (! $originalLine) {
                    throw ValidationException::withMessages([
                        'lines' => 'One of the selected lines does not belong to this order.',
                    ]);
                }

                $alreadyRefunded = $this->quantityAlreadyRefunded($originalLine);
                $refundableQty = round((float) $originalLine->quantity - $alreadyRefunded, 3);

                if ($refundLineInput['quantity'] > $refundableQty) {
                    throw ValidationException::withMessages([
                        'lines' => "Cannot refund more than {$refundableQty} of \"{$originalLine->description}\" — it's already been partially refunded.",
                    ]);
                }

                // Refund lines mirror the original line's rate/tax, scaled
                // to the quantity actually being returned — negative
                // quantity and negative total, since this is money/stock
                // flowing back out.
                $refundQty = (float) $refundLineInput['quantity'];
                $unitPrice = (float) $originalLine->unit_price;
                $grossRefund = $refundQty * $unitPrice;

                $taxAmount = 0.0;
                if ($originalLine->tax) {
                    $taxAmount = $originalLine->tax->type === 'percentage'
                        ? round($grossRefund * ((float) $originalLine->tax->rate / 100), 2)
                        : round(((float) $originalLine->tax->rate) * ($refundQty / (float) $originalLine->quantity), 2);
                }

                $lineTotal = round($grossRefund + $taxAmount, 2);

                DocumentLine::create([
                    'document_id' => $refundDocument->id,
                    'variant_id' => $originalLine->variant_id,
                    'description' => $originalLine->description,
                    'quantity' => -$refundQty,
                    'unit_price' => $unitPrice,
                    'tax_id' => $originalLine->tax_id,
                    'tax_amount' => -$taxAmount,
                    'discount_percent' => 0,
                    'discount_amount' => 0,
                    'line_total' => -$lineTotal,
                    'is_custom' => $originalLine->is_custom,
                ]);

                $refundTotal += $lineTotal;

                if ($originalLine->variant_id) {
                    StockMovement::create([
                        'variant_id' => $originalLine->variant_id,
                        'location_id' => $document->location_id,
                        'user_id' => $request->user()->id,
                        'quantity_change' => $refundQty,
                        'type' => 'return',
                        'reference_type' => Document::class,
                        'reference_id' => $refundDocument->id,
                        'note' => "Refund on order {$document->number}",
                    ]);
                }
            }

            $refundDocument->recalculateTotals();

            Payment::create([
                'document_id' => $refundDocument->id,
                'method' => $validated['refund_method'],
                'amount' => -$refundTotal,
                'paid_at' => now(),
                'reference' => "Refund for {$document->number}",
            ]);

            return $refundDocument;
        });

        ActivityLog::log(
            'pos',
            'refund_issued',
            "Refunded " . number_format($refund->total, 2) . " against order {$document->number}",
            $refund
        );

        return redirect()->route('pos.history.show', ['document' => $document->id])
            ->with('success', "Refund {$refund->number} recorded.");
    }

    /**
     * Sum of quantity already refunded for a specific original line,
     * across any prior refund documents linked to its parent order.
     */
    private function quantityAlreadyRefunded(DocumentLine $originalLine): float
    {
        return (float) DocumentLine::whereHas(
            'document',
            fn ($q) => $q->where('type', 'pos_refund')
                ->where('source_document_id', $originalLine->document_id)
        )
            ->where('variant_id', $originalLine->variant_id)
            ->sum(DB::raw('-quantity')); // refund lines store negative quantity; flip sign to get a positive "amount refunded"
    }

    /**
     * Sum of all money refunded against a given original order so far.
     */
    private function totalAlreadyRefunded(Document $document): float
    {
        return (float) Document::where('type', 'pos_refund')
            ->where('source_document_id', $document->id)
            ->sum('total');
    }
}