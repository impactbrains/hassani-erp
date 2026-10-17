<?php

namespace App\Domain\Pos;

use App\Domain\Sales\CreditControl;
use App\Models\CreditNote;
use App\Models\Payment;
use App\Models\SalesInvoice;
use App\Support\Decimal;
use App\Support\Settings\Settings;
use App\Support\Tenancy\CurrentCompany;

/**
 * Everything printed on an 80 mm / 58 mm receipt (or an A4 invoice), with
 * Urdu product names when the receipt language asks for them. Reprints are
 * marked as copies.
 */
final class ReceiptData
{
    public function __construct(private readonly CreditControl $credit)
    {
    }

    /** @return array<string, mixed> */
    public function forInvoice(SalesInvoice $invoice, bool $reprint = false): array
    {
        $invoice->loadMissing(['store', 'cashier:id,name', 'customer.customerProfile', 'lines.variant.product', 'lines.variantUnit.unit']);
        $company = CurrentCompany::get();
        $payments = Payment::query()->with('method:id,name,name_ur,type')
            ->whereHas('allocations', fn ($q) => $q->where('allocatable_type', $invoice->getMorphClass())->where('allocatable_id', $invoice->id))
            ->where('kind', 'sale')->orderBy('id')->get();

        return [
            'kind' => $invoice->kind === 'pos' ? 'receipt' : 'invoice',
            'reprint' => $reprint,
            'language' => Settings::get('pos.receipt_language', $invoice->store_id),
            'width' => Settings::get('pos.receipt_width', $invoice->store_id),
            'business' => ['name' => $company?->name, 'ntn' => $company?->ntn, 'strn' => $company?->strn, 'phone' => $invoice->store->phone ?? $company?->phone],
            'store' => ['name' => $invoice->store->name, 'name_ur' => $invoice->store->name_ur, 'address' => $invoice->store->address],
            'number' => $invoice->number,
            'posted_at' => $invoice->posted_at?->setTimezone($invoice->store->timezone())->format('d-m-Y h:i A'),
            'cashier' => $invoice->cashier?->name,
            'customer' => $invoice->customer ? ['name' => $invoice->customer->name, 'code' => $invoice->customer->code, 'phone' => $invoice->customer->phone] : ($invoice->customer_name ? ['name' => $invoice->customer_name] : null),
            'lines' => $invoice->lines->map(fn ($l) => [
                'name' => $l->description, 'name_ur' => $l->description_ur, 'quantity' => $l->quantity, 'unit' => $l->variantUnit?->unit?->code,
                'unit_price' => $l->unit_price, 'mrp' => $l->mrp, 'discount' => Decimal::money(Decimal::add($l->line_discount ?? '0', $l->cart_discount_share ?? '0')), 'total' => $l->line_total,
            ])->all(),
            'totals' => [
                'subtotal' => $invoice->subtotal, 'discount' => $invoice->discount_total, 'tax' => $invoice->tax_total,
                'delivery' => $invoice->delivery_charge, 'rounding' => $invoice->rounding_adjustment, 'total' => $invoice->total,
                'paid' => $invoice->amount_paid, 'khata' => $invoice->credit_extended,
            ],
            'payments' => $payments->map(fn (Payment $p) => ['method' => $p->method?->name, 'method_ur' => $p->method?->name_ur, 'amount' => $p->amount, 'tendered' => $p->tendered_amount, 'change' => $p->change_amount, 'reference' => $p->reference, 'pending' => $p->verification_status === 'pending'])->all(),
            'khata_balance' => $invoice->customer_id ? $this->credit->balance($invoice->customer_id) : null,
            'items_count' => $invoice->lines->count(),
            'footer' => Settings::get('pos.receipt_footer', $invoice->store_id),
            'footer_ur' => Settings::get('pos.receipt_footer_ur', $invoice->store_id),
            'open_drawer' => $payments->contains(fn (Payment $p) => $p->method?->type === 'cash'),
        ];
    }

    /** @return array<string, mixed> */
    public function forCreditNote(CreditNote $note): array
    {
        $note->loadMissing(['store', 'invoice', 'reasonCode']);

        return [
            'kind' => 'credit_note', 'number' => $note->number, 'original' => $note->invoice?->number,
            'store' => ['name' => $note->store->name], 'business' => ['name' => CurrentCompany::get()?->name],
            'posted_at' => $note->posted_at?->setTimezone($note->store->timezone())->format('d-m-Y h:i A'),
            'reason' => $note->reasonCode?->label, 'settlement' => $note->settlement, 'total' => $note->total,
            'lines' => \Illuminate\Support\Facades\DB::table('credit_note_lines as c')->join('sales_invoice_lines as l', 'l.id', '=', 'c.sales_invoice_line_id')->where('c.credit_note_id', $note->id)
                ->get(['l.description as name', 'l.description_ur as name_ur', 'c.quantity', 'c.line_total as total'])->map(fn ($r) => (array) $r)->all(),
            'width' => Settings::get('pos.receipt_width', $note->store_id),
            'open_drawer' => $note->settlement === 'refund',
        ];
    }
}
