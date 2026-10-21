<?php

use App\Domain\Catalog\ProductService;
use App\Domain\Inventory\Availability;
use App\Domain\Inventory\OpeningStock;
use App\Models\Product;
use App\Models\PriceBook;
use App\Models\StockMovement;
use App\Models\Unit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->setUpCompany();
    $this->owner = $this->userWithRole('owner');
    $this->pcs = Unit::query()->where('code', 'pcs')->firstOrFail();
    $this->retail = PriceBook::query()->where('code', 'RETAIL')->firstOrFail();

    $this->productData = fn (array $variant = []) => [
        'name' => 'Opening Test Soap',
        'type' => 'stocked',
        'variants' => [$variant + [
            'sku' => 'OPEN-1', 'name' => 'Opening Test Soap', 'base_unit_id' => $this->pcs->id,
            'units' => [['unit_id' => $this->pcs->id, 'factor' => '1', 'is_default_sale' => true]],
            'prices' => [['price_book_id' => $this->retail->id, 'unit_id' => $this->pcs->id, 'price' => '100']],
        ]],
    ];

    /** Mirrors ProductController::store(): check, then save product and stock together. */
    $this->createWithOpening = function (array $data) {
        $opening = app(OpeningStock::class);
        $requested = OpeningStock::requested($data['variants']);
        $opening->assertCanRecord($this->owner, $this->store, $data['type'], (bool) ($data['track_batches'] ?? false), (bool) ($data['track_expiry'] ?? false), $requested);

        return DB::transaction(function () use ($data, $opening, $requested) {
            $product = app(ProductService::class)->save($data);

            return [$product, $opening->record($this->owner, $this->store, $product, $requested)];
        });
    };
});

it('records opening stock for the current store as an opening movement posted to accounting', function () {
    [$product, $adjustment] = ($this->createWithOpening)(($this->productData)(['opening_quantity' => '25', 'opening_cost' => '60']));

    expect($adjustment->status)->toBe('posted');
    $variant = $product->variants->first();
    expect(app(Availability::class)->available($this->store->id, $variant->id))->toBe('25.000');
    expect(StockMovement::query()->where('variant_id', $variant->id)->where('movement_type', 'opening')->count())->toBe(1);
    expect($this->accountBalance('inventory'))->toBe('1500.00')
        ->and($this->accountBalance('opening_balance_equity'))->toBe('-1500.00');
    $this->assertLedgerBalanced();
});

it('creates the product without stock when no quantity is given', function () {
    [$product, $adjustment] = ($this->createWithOpening)(($this->productData)());

    expect($adjustment)->toBeNull()
        ->and(app(Availability::class)->available($this->store->id, $product->variants->first()->id))->toBe('0.000')
        ->and($this->accountBalance('inventory'))->toBe('0.00');
});

it('asks for a cost before saving anything', function () {
    expect(fn () => ($this->createWithOpening)(($this->productData)(['opening_quantity' => '5'])))->toThrow(ValidationException::class);
    expect(Product::query()->where('name', 'Opening Test Soap')->exists())->toBeFalse();
});

it('refuses opening stock on a service item', function () {
    $data = ($this->productData)(['opening_quantity' => '5', 'opening_cost' => '10']);
    $data['type'] = 'service';

    expect(fn () => ($this->createWithOpening)($data))->toThrow(ValidationException::class);
    expect(Product::query()->where('name', 'Opening Test Soap')->exists())->toBeFalse();
});

it('rolls the product back when the stock cannot be recorded', function () {
    // Pieces cannot be fractional: caught after the product row exists, so the transaction must undo it.
    expect(fn () => ($this->createWithOpening)(($this->productData)(['opening_quantity' => '2.5', 'opening_cost' => '10'])))->toThrow(ValidationException::class);
    expect(Product::query()->where('name', 'Opening Test Soap')->exists())->toBeFalse()
        ->and($this->accountBalance('inventory'))->toBe('0.00');
});

it('requires a batch number or expiry for batch-tracked products', function () {
    $data = ($this->productData)(['opening_quantity' => '5', 'opening_cost' => '10']);
    $data['track_batches'] = true;

    expect(fn () => ($this->createWithOpening)($data))->toThrow(ValidationException::class);

    $data['variants'][0]['opening_batch_no'] = 'B-1';
    [, $adjustment] = ($this->createWithOpening)($data);
    expect($adjustment->status)->toBe('posted');
});
