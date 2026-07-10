<?php

namespace App\Http\Controllers;

use App\Models\GeneratedBarcode;
use App\Models\ProductVariant;
use App\Models\Sequence;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Picqer\Barcode\BarcodeGeneratorSVG;

class BarcodeController extends Controller
{
    /**
     * Lists every variant, split into "has a barcode" and "missing one" —
     * so the store can see at a glance what's already labeled and what
     * still needs a barcode generated.
     */
    public function index(Request $request): Response
    {
        $variants = ProductVariant::with(['product', 'media'])
            ->where('is_active', true)
            ->when($request->search, fn ($q, $search) => $q->whereHas(
                'product',
                fn ($pq) => $pq->where('name', 'like', "%{$search}%")
            )->orWhere('name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%"))
            ->orderBy('name')
            ->get()
            ->map(fn (ProductVariant $v) => [
                'id' => $v->id,
                'name' => $v->displayName(),
                'sku' => $v->sku,
                'barcode' => $v->barcode,
                'price' => $v->price,
                'image_url' => $v->media?->resolvedUrl(),
            ]);

        return Inertia::render('barcode/Index', [
            'variants' => $variants,
            'filters' => $request->only(['search']),
        ]);
    }

    /**
     * Generates a new, internally-valid EAN-13 barcode and assigns it to
     * the given variant directly (used from the variant list page).
     */
    public function generate(Request $request, ProductVariant $variant)
    {
        if ($variant->barcode) {
            throw ValidationException::withMessages([
                'barcode' => 'This variant already has a barcode. Remove it first if you want to generate a new one.',
            ]);
        }

        $variant->update(['barcode' => $this->generateUniqueEan13()]);

        return response()->json([
            'id' => $variant->id,
            'barcode' => $variant->barcode,
        ]);
    }

    /**
     * Removes a variant's barcode, allowing a new one to be generated.
     */
    public function clear(ProductVariant $variant)
    {
        $variant->update(['barcode' => null]);

        return response()->json(['id' => $variant->id, 'barcode' => null]);
    }

    /**
     * Renders a printable label page for a single variant.
     */
    public function printLabel(ProductVariant $variant): Response
    {
        abort_if(! $variant->barcode, 404, 'This variant has no barcode to print.');

        $svg = $this->renderBarcodeSvg($variant->barcode);

        return Inertia::render('barcode/print/Label', [
            'labels' => [[
                'name' => $variant->displayName(),
                'price' => $variant->price,
                'barcode' => $variant->barcode,
                'barcodeSvg' => base64_encode($svg),
            ]],
            'columns' => 1,
        ]);
    }

    /**
     * --- Blank barcode generation (the barcode pool) ---
     */

    public function createBatch(): Response
    {
        return Inertia::render('barcode/GenerateBatch');
    }

    /**
     * Generates one barcode per submitted row, each with its own optional
     * label, saves each into the pool as 'unclaimed', and returns a
     * printable A4 grid sheet sized by the requested column count.
     */
    public function storeBatch(Request $request)
    {
        $validated = $request->validate([
            'rows' => ['required', 'array', 'min:1', 'max:200'],
            'rows.*.label' => ['nullable', 'string', 'max:255'],
            'columns' => ['required', 'integer', 'min:1', 'max:6'],
        ]);

        $labels = [];

        foreach ($validated['rows'] as $row) {
            $code = $this->generateUniqueEan13();
            $label = $row['label'] ?? null;

            GeneratedBarcode::create([
                'code' => $code,
                'label' => $label,
                'status' => 'unclaimed',
            ]);

            $labels[] = [
                'name' => $label,
                'price' => null,
                'barcode' => $code,
                'barcodeSvg' => base64_encode($this->renderBarcodeSvg($code)),
            ];
        }

        return Inertia::render('barcode/print/Label', [
            'labels' => $labels,
            'columns' => $validated['columns'],
        ]);
    }

    /**
     * The barcode pool — every generated code, claimed or not.
     */
    public function pool(Request $request): Response
    {
        $codes = GeneratedBarcode::orderByDesc('created_at')
            ->when($request->status, fn ($q, $status) => $q->where('status', $status))
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('barcode/Pool', [
            'codes' => $codes,
            'filters' => $request->only(['status']),
        ]);
    }

    public function markUsed(GeneratedBarcode $generatedBarcode)
    {
        $generatedBarcode->update(['status' => 'claimed']);

        return redirect()->route('barcode.pool')->with('success', 'Marked as used.');
    }

    public function markUnused(GeneratedBarcode $generatedBarcode)
    {
        $generatedBarcode->update(['status' => 'unclaimed']);

        return redirect()->route('barcode.pool')->with('success', 'Marked as unclaimed.');
    }
    /**
 * Update a pool code's label (inline edit from the pool table).
 */
public function updateLabel(Request $request, GeneratedBarcode $generatedBarcode)
{
    $validated = $request->validate([
        'label' => ['nullable', 'string', 'max:255'],
    ]);

    $generatedBarcode->update(['label' => $validated['label']]);

    return response()->json([
        'id' => $generatedBarcode->id,
        'label' => $generatedBarcode->label,
    ]);
}

/**
 * Delete a pool code entirely. Only allowed while unclaimed — a claimed
 * code represents a barcode that's already been printed and stuck on a
 * real product, so it must be reverted to unclaimed first (confirming
 * you're aware it's not actually in use) before it can be removed.
 */
public function destroy(GeneratedBarcode $generatedBarcode)
{
    if ($generatedBarcode->status === 'claimed') {
        return redirect()->route('barcode.pool')
            ->with('error', 'Mark this barcode as unclaimed before deleting it.');
    }

    $generatedBarcode->delete();

    return redirect()->route('barcode.pool')->with('success', 'Barcode deleted.');
}
    /**
     * --- Shared helpers ---
     */

    private function renderBarcodeSvg(string $code): string
    {
        $generator = new BarcodeGeneratorSVG();
        return $generator->getBarcode($code, $generator::TYPE_EAN_13, 2, 60, 'black', true);
    }

    private function generateUniqueEan13(): string
    {
        do {
            $body = Sequence::next('product_barcode');
            $code = $body . $this->ean13CheckDigit($body);
        } while (
            ProductVariant::where('barcode', $code)->exists()
            || GeneratedBarcode::where('code', $code)->exists()
        );

        return $code;
    }

    private function ean13CheckDigit(string $twelveDigits): string
    {
        $sum = 0;
        foreach (str_split($twelveDigits) as $index => $digit) {
            $sum += ($index % 2 === 0) ? (int) $digit : (int) $digit * 3;
        }

        $checkDigit = (10 - ($sum % 10)) % 10;

        return (string) $checkDigit;
    }
    /**
 * Bulk delete — only removes codes that are still 'unclaimed'. Claimed
 * codes in the selection are silently skipped (reported back in the
 * response so the frontend can tell the user how many were skipped).
 */
public function bulkDestroy(Request $request)
{
    $validated = $request->validate([
        'ids' => ['required', 'array', 'min:1'],
        'ids.*' => ['integer', 'exists:generated_barcodes,id'],
    ]);

    $codes = GeneratedBarcode::whereIn('id', $validated['ids'])->get();

    $deletable = $codes->where('status', 'unclaimed');
    $skipped = $codes->count() - $deletable->count();

    GeneratedBarcode::whereIn('id', $deletable->pluck('id'))->delete();

    $message = "Deleted {$deletable->count()} barcode(s).";
    if ($skipped > 0) {
        $message .= " Skipped {$skipped} claimed barcode(s) — unclaim them first to delete.";
    }

    return redirect()->route('barcode.pool')->with('success', $message);
}

/**
 * Bulk print — renders the same A4 grid sheet used for freshly-generated
 * batches, but fed from existing pool codes instead of newly-created ones.
 */
public function bulkPrint(Request $request): Response
{
    $validated = $request->validate([
        'ids' => ['required', 'array', 'min:1'],
        'ids.*' => ['integer', 'exists:generated_barcodes,id'],
        'columns' => ['required', 'integer', 'min:1', 'max:6'],
    ]);

    $codes = GeneratedBarcode::whereIn('id', $validated['ids'])->get();

    $labels = $codes->map(fn (GeneratedBarcode $c) => [
        'name' => $c->label,
        'price' => null,
        'barcode' => $c->code,
        'barcodeSvg' => base64_encode($this->renderBarcodeSvg($c->code)),
    ])->values();

    return Inertia::render('barcode/print/Label', [
        'labels' => $labels,
        'columns' => $validated['columns'],
    ]);
}
}