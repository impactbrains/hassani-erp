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
        $result = $importer->import($request->file('file')->getRealPath(), $request->user()->hasPermission('catalog.prices.manage'), $request->boolean('dry_run'));
        if (! $request->boolean('dry_run')) {
            Audit::record('catalog.imported', "Imported {$result['created']} products from CSV", null, new: ['created' => $result['created'], 'skipped' => $result['skipped'], 'errors' => count($result['errors'])]);
        }

        return back()->with('import_result', $result + ['dry_run' => $request->boolean('dry_run')]);
    }

    public function template(): StreamedResponse
    {
        return response()->streamDownload(function () {
            $out = new CsvWriter(fopen('php://output', 'w'));
            $out->header(ProductImporter::COLUMNS);
            $out->header(['Tapal Family Mixture 95g', 'تپال فیملی مکسچر', '', '', 'Tea', 'Tapal', 'pcs', 'ctn', '48', '50', '47', '2300', '50']);
        }, 'products-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function export(Request $request): StreamedResponse
    {
        $this->allow($request, 'catalog.products.export');
        Audit::record('catalog.exported', 'Exported product list');

        return response()->streamDownload(function () {
            $out = new CsvWriter(fopen('php://output', 'w'));
            $out->header(['sku', 'name', 'name_ur', 'category', 'brand', 'unit', 'barcodes', 'mrp', 'active']);
            ProductVariant::query()->with(['product.category', 'product.brand', 'baseUnit', 'barcodes'])->orderBy('id')->chunk(500, function ($variants) use ($out) {
                foreach ($variants as $v) {
                    $out->header([$v->sku, $v->displayName(), (string) $v->displayNameUr(), (string) $v->product?->category?->name, (string) $v->product?->brand?->name, (string) $v->baseUnit?->code, $v->barcodes->pluck('code')->implode(' '), (string) $v->mrp, $v->is_active ? 'yes' : 'no']);
                }
            });
        }, 'products-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
