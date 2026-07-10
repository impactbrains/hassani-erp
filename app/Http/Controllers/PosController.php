<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Contact;
use App\Models\Document;
use App\Models\DocumentLine;
use App\Models\Location;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sequence;
use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PosController extends Controller
{
    /**
     * Main POS screen — product catalog (grouped by parent product) and
     * every open draft order belonging to the current user, so the tab
     * bar can be restored after a refresh.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        $products = Product::with(['variants' => fn ($q) => $q->where('is_active', true)->with('media'), 'media'])
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(function (Product $product) {
                $product->image_url = $product->media?->resolvedUrl();
                $product->variants->each(fn (ProductVariant $v) => $v->image_url = $v->media?->resolvedUrl());
                return $product;
            });

        $drafts = Document::with(['lines.variant.product', 'lines.tax', 'contact'])
            ->where('type', 'pos_order')
            ->where('status', 'draft')
            ->where('user_id', $user->id)
            ->orderBy('created_at')
            ->get();

        return Inertia::render('pos/Index', [
            'products' => $products,
            'locations' => Location::orderBy('name')->get(['id', 'name']),
            'drafts' => $drafts,
            'defaultLocationId' => Location::where('is_default', true)->value('id'),
        ]);
    }

    /**
     * Live product/variant search for the POS catalog panel.
     */
    public function search(Request $request)
    {
        $search = $request->get('search', '');

        $variants = ProductVariant::with(['product', 'media'])
            ->where('is_active', true)
            ->where(function ($q) use ($search) {
                $q->whereHas('product', fn ($pq) => $pq->where('name', 'like', "%{$search}%"))
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%");
            })
            ->limit(30)
            ->get()
            ->map(fn (ProductVariant $v) => $this->variantPayload($v));

        return response()->json($variants);
    }

    /**
     * Barcode scan endpoint — looks up the variant and, if found, adds it
     * directly to the specified draft order rather than just returning
     * info, since a scan in POS means "add this to the cart".
     */
    public function scanBarcode(Request $request)
    {
        $validated = $request->validate([
            'barcode' => ['required', 'string'],
            'document_id' => ['required', 'exists:documents,id'],
        ]);

        $variant = ProductVariant::where('barcode', $validated['barcode'])->first();

        if (! $variant) {
            return response()->json(['found' => false], 404);
        }

        $document = $this->findOwnedDraft($request, $validated['document_id']);

        // Scanning is warn-only, never blocked — the cashier physically has
        // the item in hand, so refusing to add it would be disruptive. We
        // still surface a warning so a stock discrepancy gets noticed.
        // Checked against the new total (existing line + this scan), same
        // reasoning as addLine().
        $existingLine = $document->lines()
            ->where('variant_id', $variant->id)
            ->where('is_custom', false)
            ->first();
        $newTotalQuantity = 1 + (float) ($existingLine?->quantity ?? 0);

        $stockWarning = $this->checkStockWarning($document, $variant, $newTotalQuantity);

        $this->addOrIncrementLine($document, $variant, 1);
        $document->recalculateTotals();

        return response()->json([
            'found' => true,
            'document' => $document->fresh(['lines.variant.product', 'lines.tax', 'contact']),
            'stock_warning' => $stockWarning,
        ]);
    }

    /**
     * Starts a new draft order (a new tab).
     */
    public function startDraft(Request $request)
    {
        $validated = $request->validate([
            'location_id' => ['required', 'exists:locations,id'],
        ]);

        $document = Document::create([
            'type' => 'pos_order',
            'number' => Sequence::next('pos_order'),
            'location_id' => $validated['location_id'],
            'user_id' => $request->user()->id,
            'status' => 'draft',
            'currency' => 'PKR',
        ]);

        return response()->json($document);
    }

    /**
     * Closes/discards an empty or unwanted draft order.
     */
    /**
     * Returns the current state of a document the user owns — used by the
     * frontend to re-check a tab's status before switching to it or
     * proceeding to checkout, since a tab can go stale if checkout was
     * already completed in another browser tab.
     */
    public function refreshDraft(Request $request, Document $document)
    {
        abort_unless($document->user_id === $request->user()->id, 403);

        return response()->json(
            $document->load(['lines.variant.product', 'lines.tax', 'contact'])
        );
    }

    public function discardDraft(Request $request, Document $document)
    {
        $this->authorizeDraft($request, $document);

        $document->lines()->delete();
        $document->delete();

        return response()->json(['success' => true]);
    }

    public function addLine(Request $request, Document $document)
    {
        $this->authorizeDraft($request, $document);

        $validated = $request->validate([
            'variant_id' => ['required', 'exists:product_variants,id'],
            'quantity' => ['nullable', 'numeric', 'min:0.001'],
        ]);

        $variant = ProductVariant::findOrFail($validated['variant_id']);
        $quantity = (float) ($validated['quantity'] ?? 1);

        // Click-to-add is hard-blocked at available stock — unlike a
        // barcode scan, there's no physical-item-in-hand signal here, so
        // we refuse rather than just warn. Checked against the NEW TOTAL
        // (existing line quantity + this increment), not just the
        // incoming delta — otherwise clicking an already-at-limit item
        // again would pass a check against "1 unit" even though the
        // resulting total would exceed stock.
        $existingLine = $document->lines()
            ->where('variant_id', $variant->id)
            ->where('is_custom', false)
            ->first();
        $newTotalQuantity = $quantity + (float) ($existingLine?->quantity ?? 0);

        $stockWarning = $this->checkStockWarning($document, $variant, $newTotalQuantity);
        if ($stockWarning) {
            throw ValidationException::withMessages([
                'quantity' => $stockWarning,
            ]);
        }

        $this->addOrIncrementLine($document, $variant, $quantity);
        $document->recalculateTotals();

        return response()->json($document->fresh(['lines.variant.product', 'lines.tax', 'contact']));
    }

    /**
     * Adds a manual/off-catalog item — something not in the product
     * database (e.g. a one-off item, a favor for a regular customer).
     * Always quantity 1 and tax-free, since there's no variant/product
     * record to derive a rate or tax from. Each submission adds a new
     * line rather than incrementing an existing one, since two custom
     * lines with the same name aren't necessarily "the same item" the
     * way two lines of the same catalog variant would be.
     */
    public function addCustomLine(Request $request, Document $document)
    {
        $this->authorizeDraft($request, $document);

        $validated = $request->validate([
            'description' => ['required', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0.01'],
        ]);

        $line = new DocumentLine([
            'document_id' => $document->id,
            'variant_id' => null,
            'description' => $validated['description'],
            'quantity' => 1,
            'unit_price' => $validated['price'],
            'tax_id' => null,
            'discount_percent' => 0,
            'discount_amount' => 0,
            'is_custom' => true,
        ]);
        $line->recalculate();
        $line->save();

        $document->recalculateTotals();

        return response()->json($document->fresh(['lines.variant.product', 'lines.tax', 'contact']));
    }

    public function updateLine(Request $request, Document $document, DocumentLine $line)
    {
        $this->authorizeDraft($request, $document);
        abort_unless($line->document_id === $document->id, 404);

        $validated = $request->validate([
            'quantity' => ['nullable', 'numeric', 'min:0.001'],
            'target_price' => ['nullable', 'numeric', 'min:0.01'],
        ]);

        $hasQuantity = array_key_exists('quantity', $validated) && $validated['quantity'] !== null;
        $hasTargetPrice = array_key_exists('target_price', $validated) && $validated['target_price'] !== null;

        if ($hasQuantity && $hasTargetPrice) {
            throw ValidationException::withMessages([
                'quantity' => 'Update either quantity or price, not both at once.',
            ]);
        }

        if ($hasTargetPrice) {
            // Note: NOT gated by can_edit_price — that flag controls
            // discounting only. Entering a target price here is a
            // quantity-entry convenience (e.g. "customer paid Rs 125 for
            // rice priced at Rs 250/kg" → 0.5kg), available for any
            // variant regardless of discount permissions.
            $variant = $line->variant;

            // unit_price always stays pinned to the variant's base rate —
            // we back-solve quantity from it, never mutate the rate itself.
            // e.g. rice is Rs 300/kg; entering Rs 150 means quantity becomes
            // 0.5, not that the per-kg rate changed.
            $basePrice = (float) $line->unit_price;
            if ($basePrice <= 0) {
                throw ValidationException::withMessages([
                    'target_price' => 'This item has no base price to calculate quantity from.',
                ]);
            }

            $line->quantity = $this->normalizeQuantity(
                $validated['target_price'] / $basePrice,
                $variant
            );

            // A manually-entered price supersedes any existing discount —
            // if they want a discount on top of the new price, they can
            // re-apply one afterward as a separate action.
            $line->discount_percent = 0;
            $line->discount_amount = 0;
        }

        if ($hasQuantity) {
            $line->quantity = $this->normalizeQuantity($validated['quantity'], $line->variant);
        }

        // Same hard-block as addLine() — direct quantity/price edits have
        // no "item in hand" signal the way a scan does, so we refuse
        // rather than warn. Checked against the newly-set quantity as a
        // whole, since a draft's lines haven't left the stock ledger yet
        // (that only happens at checkout).
        if (($hasQuantity || $hasTargetPrice) && $line->variant) {
            $stockWarning = $this->checkStockWarning($document, $line->variant, (float) $line->quantity);
            if ($stockWarning) {
                throw ValidationException::withMessages([
                    'quantity' => $stockWarning,
                ]);
            }
        }

        $line->recalculate();
        $line->save();
        $document->recalculateTotals();

        return response()->json($document->fresh(['lines.variant.product', 'lines.tax', 'contact']));
    }

    public function removeLine(Request $request, Document $document, DocumentLine $line)
    {
        $this->authorizeDraft($request, $document);
        abort_unless($line->document_id === $document->id, 404);

        $line->delete();
        $document->recalculateTotals();

        return response()->json($document->fresh(['lines.variant.product', 'lines.tax', 'contact']));
    }

    /**
     * Apply a discount to a single line. Only allowed if that line's
     * variant has can_edit_price enabled.
     */
    public function applyLineDiscount(Request $request, Document $document, DocumentLine $line)
    {
        $this->authorizeDraft($request, $document);
        abort_unless($line->document_id === $document->id, 404);

        $variant = $line->variant;
        if (! $variant || ! $variant->can_edit_price) {
            throw ValidationException::withMessages([
                'discount' => 'Price editing is not enabled for this product.',
            ]);
        }

        $validated = $request->validate([
            'discount_type' => ['required', 'in:percentage,fixed'],
            'discount_value' => ['required', 'numeric', 'min:0'],
        ]);

        $line->discount_percent = $validated['discount_type'] === 'percentage' ? $validated['discount_value'] : 0;
        $line->discount_amount = $validated['discount_type'] === 'fixed' ? $validated['discount_value'] : 0;
        $line->recalculate();
        $line->save();
        $document->recalculateTotals();

        return response()->json($document->fresh(['lines.variant.product', 'lines.tax', 'contact']));
    }

    /**
     * Apply a discount to the whole order (used when nothing/no line is
     * selected in the cart UI).
     */
    public function applyOrderDiscount(Request $request, Document $document)
    {
        $this->authorizeDraft($request, $document);

        $validated = $request->validate([
            'discount_type' => ['nullable', 'in:percentage,fixed'],
            'discount_value' => ['nullable', 'numeric', 'min:0'],
        ]);

        $document->discount_type = $validated['discount_type'] ?? null;
        $document->discount_value = $validated['discount_value'] ?? null;
        $document->save();
        $document->recalculateTotals();

        return response()->json($document->fresh(['lines.variant.product', 'lines.tax', 'contact']));
    }

    public function assignContact(Request $request, Document $document)
    {
        $this->authorizeDraft($request, $document);

        $validated = $request->validate([
            'contact_id' => ['nullable', 'exists:contacts,id'],
        ]);

        $document->update(['contact_id' => $validated['contact_id'] ?? null]);

        return response()->json($document->fresh(['lines.variant.product', 'lines.tax', 'contact']));
    }

    /**
     * Customer search for the contact picker.
     */
    public function searchContacts(Request $request)
    {
        $search = $request->get('search', '');

        $contacts = Contact::customers()
            ->where('is_active', true)
            ->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            })
            ->limit(20)
            ->get(['id', 'name', 'phone']);

        return response()->json($contacts);
    }

    /**
     * Quick-create a customer from the POS contact search panel, mirroring
     * the inline "create new" pattern used elsewhere (categories, units).
     */
    public function storeContact(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
        ]);

        $contact = Contact::create([
            'type' => 'customer',
            'name' => $validated['name'],
            'phone' => $validated['phone'] ?? null,
            'is_active' => true,
        ]);

        return response()->json([
            'id' => $contact->id,
            'name' => $contact->name,
            'phone' => $contact->phone,
        ]);
    }

    /**
     * Finalizes the order: validates payment covers the total, records
     * Payment rows, writes `sale` StockMovements per line (decrementing
     * stock at the order's location), flips status to paid, and logs it.
     */
    /**
     * The checkout screen — payment method selection, change-due
     * calculation, and the three end-of-sale actions (continue, print
     * receipt, print invoice). Separate route from the main POS screen
     * per the decided flow.
     */
    public function showCheckout(Request $request, Document $document)
    {
        abort_unless($document->user_id === $request->user()->id, 403);

        // If this order was already confirmed/paid (e.g. the cashier
        // refreshed or revisited an old checkout tab after completing the
        // sale elsewhere), there's nothing left to check out — send them
        // to the order's record instead of throwing a hard error.
        if ($document->status !== 'draft') {
            return redirect()->route('pos.history.show', ['document' => $document->id]);
        }

        $document->load(['lines.variant.product', 'lines.tax', 'contact', 'location']);

        return Inertia::render('pos/Checkout', [
            'document' => $document,
        ]);
    }

    public function checkout(Request $request, Document $document)
    {
        $this->authorizeDraft($request, $document);

        $validated = $request->validate([
            'payments' => ['required', 'array', 'min:1'],
            'payments.*.method' => ['required', 'in:cash,card,bank_transfer,other'],
            'payments.*.amount' => ['required', 'numeric', 'min:0.01'],
            'payments.*.reference' => ['nullable', 'string', 'max:255'],
        ]);

        $document->loadMissing('lines');

        if ($document->lines->isEmpty()) {
            throw ValidationException::withMessages(['lines' => 'Cannot check out an empty order.']);
        }

        $totalPaid = collect($validated['payments'])->sum('amount');

        if (round($totalPaid, 2) < round((float) $document->total, 2)) {
            throw ValidationException::withMessages([
                'payments' => 'Payment does not cover the full order total.',
            ]);
        }

        $document = DB::transaction(function () use ($document, $validated, $totalPaid, $request) {
            foreach ($validated['payments'] as $paymentData) {
                Payment::create([
                    'document_id' => $document->id,
                    'method' => $paymentData['method'],
                    'amount' => $paymentData['amount'],
                    'reference' => $paymentData['reference'] ?? null,
                    'paid_at' => now(),
                ]);
            }

            foreach ($document->lines as $line) {
                if (! $line->variant_id) {
                    continue; // custom/manual lines with no linked variant don't move stock
                }

                StockMovement::create([
                    'variant_id' => $line->variant_id,
                    'location_id' => $document->location_id,
                    'user_id' => $request->user()->id,
                    'quantity_change' => -1 * (float) $line->quantity,
                    'type' => 'sale',
                    'reference_type' => Document::class,
                    'reference_id' => $document->id,
                    'note' => "Sale on order {$document->number}",
                ]);
            }

            $document->update([
                'status' => 'paid',
                'amount_paid' => $totalPaid,
                'confirmed_at' => now(),
            ]);

            return $document;
        });

        ActivityLog::log('pos', 'sale_completed', "Completed sale {$document->number} for " . number_format((float) $document->total, 2), $document);

        return response()->json($document->fresh(['lines.variant.product', 'lines.tax', 'contact', 'payments']));
    }

    /**
     * Adds a variant to a document's lines, or increments quantity if it's
     * already present as a non-custom line for that variant.
     */
    private function addOrIncrementLine(Document $document, ProductVariant $variant, float $quantity): void
    {
        $existingLine = $document->lines()
            ->where('variant_id', $variant->id)
            ->where('is_custom', false)
            ->first();

        if ($existingLine) {
            $existingLine->quantity = $this->normalizeQuantity(
                (float) $existingLine->quantity + $quantity,
                $variant
            );
            $existingLine->recalculate();
            $existingLine->save();
            return;
        }

        $taxId = $variant->tax_id ?? $variant->product?->tax_id;

        $line = new DocumentLine([
            'document_id' => $document->id,
            'variant_id' => $variant->id,
            'description' => $variant->displayName(),
            'quantity' => $this->normalizeQuantity($quantity, $variant),
            'unit_price' => $variant->price,
            'tax_id' => $taxId,
            'discount_percent' => 0,
            'discount_amount' => 0,
            'is_custom' => false,
        ]);
        $line->recalculate();
        $line->save();
    }

    /**
     * Enforces whole-number quantities for non-loose variants (e.g. a
     * boxed/packaged item that can't be sold as "1.2 units"). Loose items
     * (sold by variable weight/measure, like rice by the kg) are left as
     * entered, with 3-decimal precision. Rounds to nearest whole number
     * rather than truncating, and floors at 1 so a rounded-down entry
     * never produces a zero-quantity line.
     */
    /**
     * Checks whether adding/setting $requestedQuantity for $variant on
     * this document's cart would exceed what's actually in stock at the
     * order's location. Returns a formatted warning message if it would,
     * or null if the quantity is fine.
     *
     * Skips the check entirely for variants with track_inventory
     * disabled — no ledger is kept for them, so there's no stock figure
     * to compare against; they're treated as always available.
     *
     * Checked against total stock on hand directly — a draft order's own
     * lines haven't left the stock ledger yet (that only happens at
     * checkout), so there's nothing to "add back" for the line being
     * edited; $requestedQuantity is simply the new total being asked for.
     */
    private function checkStockWarning(Document $document, ProductVariant $variant, float $requestedQuantity): ?string
    {
        if (! $variant->track_inventory) {
            return null;
        }

        $available = $variant->stockOnHand($document->location_id);

        if ($available >= $requestedQuantity) {
            return null;
        }

        $itemName = $variant->displayName();
        $unit = $variant->unit?->name ?? $variant->unit?->short_code ?? 'units';

        if ($available <= 0) {
            return "Warning, {$itemName} is not available (0 stock).";
        }

        return sprintf(
            'Warning, only %s %s available out of %s requested.',
            $this->formatQtyForMessage($available),
            $unit,
            $this->formatQtyForMessage($requestedQuantity)
        );
    }

    /**
     * Formats a quantity for a warning message — trims trailing zeros
     * so "5.000" reads as "5", but keeps up to 3 decimals for genuinely
     * fractional amounts (e.g. loose items).
     */
    private function formatQtyForMessage(float $quantity): string
    {
        return rtrim(rtrim(number_format($quantity, 3, '.', ''), '0'), '.') ?: '0';
    }

    private function normalizeQuantity(float $quantity, ?ProductVariant $variant): float
    {
        if ($variant && $variant->is_loose) {
            // 6 decimals (matching the widened schema precision) rather
            // than 3 — at 3 decimals, deriving quantity from a typed
            // price (e.g. Rs 200 / Rs 280 per kg = 0.714285714...) would
            // round to 0.714, and multiplying that back out no longer
            // reproduces the exact price the cashier typed (199.92
            // instead of 200.00). 6 decimals keeps the round-trip
            // accurate to the cent for any realistic per-unit price.
            return round($quantity, 6);
        }

        return (float) max(1, round($quantity));
    }

    private function variantPayload(ProductVariant $variant): array
    {
        return [
            'id' => $variant->id,
            'name' => $variant->displayName(),
            'sku' => $variant->sku,
            'price' => $variant->price,
            'can_edit_price' => $variant->can_edit_price,
            'is_loose' => $variant->is_loose,
            'image_url' => $variant->media?->resolvedUrl(),
        ];
    }

    /**
     * Confirms the given document ID belongs to the current user and is
     * still a draft, then returns it. Used by scanBarcode(), which
     * receives document_id as input rather than a route-model-bound param.
     */
    private function findOwnedDraft(Request $request, int $documentId): Document
    {
        $document = Document::where('id', $documentId)
            ->where('user_id', $request->user()->id)
            ->where('status', 'draft')
            ->firstOrFail();

        return $document;
    }

    /**
     * Guards every cart-mutation endpoint: the document must belong to the
     * current user and still be in draft status. Once confirmed/paid, an
     * order is immutable through these endpoints.
     */
    private function authorizeDraft(Request $request, Document $document): void
    {
        abort_unless($document->user_id === $request->user()->id, 403);
        abort_unless($document->status === 'draft', 422, 'This order is no longer editable.');
    }
}