<?php

namespace App\Http\Controllers\Catalog;

use App\Domain\Catalog\ProductImporter;
use App\Domain\Reporting\CsvWriter;
use App\Http\Controllers\Controller;
use App\Models\ProductVariant;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductImportController extends Controller
{
    public function show(Request $request): Response
    {
        $this->allow($request, 'catalog.products.import');

        return Inertia::render('catalog/Import', ['columns' => ProductImporter::COLUMNS, 'result' => $request->session()->get('import_result')]);
    }

    public function store(Request $request, ProductImporter $importer): RedirectResponse
    {
        $this->allow($request, 'catalog.products.import');
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:10240'], 'dry_run' => ['boolean']]);
        $result = $importer->import($request->file('file')->getRealPath(), $request->user(), $this->currentStore($request), $request->user()->hasPermission('catalog.prices.manage'), $request->boolean('dry_run'));
        if (! $request->boolean('dry_run')) {
            $stock = $result['stock_rows'] ? ", with opening stock for {$result['stock_rows']} ({$result['adjustment']})" : '';
            Audit::record('catalog.imported', "Imported {$result['created']} products from CSV{$stock}", null, new: ['created' => $result['created'], 'skipped' => $result['skipped'], 'errors' => count($result['errors']), 'stock_rows' => $result['stock_rows'], 'adjustment' => $result['adjustment']]);
        }

        return back()->with('import_result', $result + ['dry_run' => $request->boolean('dry_run')]);
    }

    public function template(): StreamedResponse
    {
        return response()->streamDownload(function () {
            $out = new CsvWriter(fopen('php://output', 'w'));
            $out->header(ProductImporter::COLUMNS);
            $out->header(['Tapal Family Mixture 95g', 'تپال فیملی مکسچر', '', '', 'Tea', 'Tapal', 'pcs', 'ctn', '48', '42', '50', '47', '50', '2300', '2200', '120', '', '', '']);
        }, 'products-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function export(Request $request): StreamedResponse
    {
        $this->allow($request, 'catalog.products.export');
        Audit::record('catalog.exported', 'Exported product list');
        $showCost = $request->user()->hasPermission('catalog.costs.view');

        return response()->streamDownload(function () {
            $out = new CsvWriter(fopen('php://output', 'w'));
            $resolver = app(\App\Domain\Catalog\PriceResolver::class);
            $inStore = $resolver->defaultBookId();
            $wholesale = $resolver->wholesaleBookId();
            $out->header(['sku', 'name', 'name_ur', 'category', 'brand', 'unit', 'barcodes', ...($showCost ? ['purchase_price'] : []), 'in_store_price', 'wholesale_price', 'market_price', 'active']);
            ProductVariant::query()->with(['product.category', 'product.brand', 'baseUnit', 'barcodes', 'units'])->orderBy('id')->chunk(500, function ($variants) use ($out, $resolver, $inStore, $wholesale, $showCost) {
                foreach ($variants as $v) {
                    $base = $v->units->firstWhere('is_base', true);
                    $out->header([$v->sku, $v->displayName(), (string) $v->displayNameUr(), (string) $v->product?->category?->name, (string) $v->product?->brand?->name, (string) $v->baseUnit?->code, $v->barcodes->pluck('code')->implode(' '),
                        ...($showCost ? [(string) $v->purchase_price] : []),
                        (string) ($base ? $resolver->bookPrice($v, $base, $inStore) : ''), (string) ($base ? $resolver->bookPrice($v, $base, $wholesale) : ''), (string) $v->mrp, $v->is_active ? 'yes' : 'no']);
                }
            });
        }, 'products-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
