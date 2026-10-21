<?php

use App\Domain\Catalog\ProductImporter;
use App\Domain\Inventory\Availability;
use App\Exceptions\BusinessRuleException;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockAdjustment;
use App\Models\StockBatch;
use App\Models\StockMovement;

beforeEach(function () {
    $this->setUpCompany();
    $this->owner = $this->userWithRole('owner');

    /** Writes a CSV (header + rows) to a temp file and runs the importer on it as the owner, in the current store. */
    $this->importCsv = function (array $rows, bool $dryRun = false, $user = null) {
        $path = tempnam(sys_get_temp_dir(), 'imp');
        $out = fopen($path, 'w');
        foreach ($rows as $row) {
            fputcsv($out, $row);
        }
        fclose($out);

        try {
            return app(ProductImporter::class)->import($path, $user ?? $this->owner, $this->store, true, $dryRun);
        } finally {
            @unlink($path);
        }
    };
    $this->stockOf = fn (string $sku) => app(Availability::class)->available($this->store->id, ProductVariant::query()->where('sku', $sku)->firstOrFail()->id);
    $this->header = ['name', 'unit', 'sku', 'retail_price', 'opening_quantity', 'opening_cost', 'opening_batch_no', 'opening_expiry_date'];
});

it('records the opening stock of every row as one opening adjustment posted to accounting', function () {
    $result = ($this->importCsv)([
        $this->header,
        ['Import Soap', 'pcs', 'IMP-1', '100', '10', '5', '', ''],
        ['Import Rice', 'kg', 'IMP-2', '200', '12.5', '4', '', ''],
        ['Import Salt', 'pcs', 'IMP-3', '30', '', '', '', ''],
    ]);

    expect($result['created'])->toBe(3)->and($result['errors'])->toBe([])
        ->and($result['stock_rows'])->toBe(2)->and($result['stock_pending'])->toBeFalse()->and($result['adjustment'])->not->toBeNull();
    expect(StockAdjustment::query()->where('kind', 'opening')->count())->toBe(1);
    expect(($this->stockOf)('IMP-1'))->toBe('10.000')
        ->and(($this->stockOf)('IMP-2'))->toBe('12.500')
        ->and(($this->stockOf)('IMP-3'))->toBe('0.000');
    expect(StockMovement::query()->where('movement_type', 'opening')->count())->toBe(2);
    // 10 x 5 + 12.5 x 4
    expect($this->accountBalance('inventory'))->toBe('100.00')
        ->and($this->accountBalance('opening_balance_equity'))->toBe('-100.00');
    $this->assertLedgerBalanced();
});

it('still imports a file without any opening columns', function () {
    $result = ($this->importCsv)([['name', 'unit', 'sku'], ['Plain Soap', 'pcs', 'IMP-9']]);

    expect($result['created'])->toBe(1)->and($result['stock_rows'])->toBe(0)->and($result['adjustment'])->toBeNull();
    expect(StockAdjustment::query()->count())->toBe(0);
});

it('saves nothing on a check-only run but reports what would happen', function () {
    $result = ($this->importCsv)([$this->header, ['Dry Soap', 'pcs', 'DRY-1', '100', '10', '5', '', '']], dryRun: true);

    expect($result['created'])->toBe(1)->and($result['stock_rows'])->toBe(1)->and($result['adjustment'])->toBeNull();
    expect(Product::query()->where('name', 'Dry Soap')->exists())->toBeFalse()
        ->and(StockAdjustment::query()->count())->toBe(0)
        ->and($this->accountBalance('inventory'))->toBe('0.00');
});

it('skips a row whose SKU exists together with its stock, so a second upload cannot count twice', function () {
    ($this->importCsv)([$this->header, ['Twice Soap', 'pcs', 'TWICE-1', '100', '10', '5', '', '']]);
    $again = ($this->importCsv)([$this->header, ['Twice Soap', 'pcs', 'TWICE-1', '100', '10', '5', '', '']]);

    expect($again['created'])->toBe(0)->and($again['skipped'])->toBe(1)->and($again['stock_rows'])->toBe(0);
    expect(($this->stockOf)('TWICE-1'))->toBe('10.000')
        ->and(StockAdjustment::query()->count())->toBe(1);
});

it('reports a row with a missing cost or a bad quantity and leaves no product for it', function () {
    $result = ($this->importCsv)([
        $this->header,
        ['Good Soap', 'pcs', 'ERR-1', '100', '10', '5', '', ''],
        ['No Cost Soap', 'pcs', 'ERR-2', '100', '10', '', '', ''],
        ['Half Soap', 'pcs', 'ERR-3', '100', '2.5', '5', '', ''],
        ['Minus Soap', 'pcs', 'ERR-4', '100', '-3', '5', '', ''],
        ['Word Soap', 'pcs', 'ERR-5', '100', 'ten', '5', '', ''],
    ]);

    expect($result['created'])->toBe(1)->and($result['errors'])->toHaveCount(4);
    expect($result['errors'][0])->toContain('Row 3')->toContain('opening_cost');
    expect($result['errors'][1])->toContain('Row 4')->toContain('opening_quantity');
    foreach (['No Cost Soap', 'Half Soap', 'Minus Soap', 'Word Soap'] as $name) {
        expect(Product::query()->where('name', $name)->exists())->toBeFalse();
    }
    expect(($this->stockOf)('ERR-1'))->toBe('10.000')
        ->and($this->accountBalance('inventory'))->toBe('50.00');
});

it('keeps batch and expiry for tracked stock and switches tracking on for that product', function () {
    $result = ($this->importCsv)([$this->header, ['Milk', 'pcs', 'BAT-1', '300', '24', '210', 'B-7', '31/03/2027']]);

    expect($result['errors'])->toBe([])->and($result['created'])->toBe(1);
    $batch = StockBatch::query()->where('batch_no', 'B-7')->firstOrFail();
    expect($batch->expiry_date->format('Y-m-d'))->toBe('2027-03-31');
    $product = Product::query()->where('name', 'Milk')->firstOrFail();
    expect($product->track_batches)->toBeTrue()->and($product->track_expiry)->toBeTrue();
    expect(($this->stockOf)('BAT-1'))->toBe('24.000');
});

it('asks for a batch number when only an expiry date is given', function () {
    $result = ($this->importCsv)([$this->header, ['Yoghurt', 'pcs', 'BAT-2', '100', '6', '60', '', '2027-03-31']]);

    expect($result['created'])->toBe(0)->and($result['errors'][0])->toContain('opening_batch_no');
    expect(Product::query()->where('name', 'Yoghurt')->exists())->toBeFalse();
});

it('imports nothing when the user is not allowed to add stock', function () {
    $cashier = $this->userWithRole('cashier', $this->store);

    expect(fn () => ($this->importCsv)([
        $this->header,
        ['Before Soap', 'pcs', 'DENY-1', '100', '', '', '', ''],
        ['Stock Soap', 'pcs', 'DENY-2', '100', '10', '5', '', ''],
    ], user: $cashier))->toThrow(BusinessRuleException::class);

    expect(Product::query()->whereIn('name', ['Before Soap', 'Stock Soap'])->exists())->toBeFalse()
        ->and(StockAdjustment::query()->count())->toBe(0);
});
