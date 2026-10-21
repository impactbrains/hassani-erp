<?php

use App\Domain\Catalog\ProductService;
use App\Exceptions\BusinessRuleException;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Unit;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->setUpCompany();
    $this->service = app(ProductService::class);
});

it('moves a product that was never stocked or sold to the Trash, hidden and inactive', function () {
    $variant = $this->makeProduct('Fresh Soap', '90');
    $product = $variant->product;

    expect($this->service->remove($product))->toBe('trashed');

    expect(Product::query()->whereKey($product->id)->exists())->toBeFalse()
        ->and(Product::onlyTrashed()->whereKey($product->id)->exists())->toBeTrue()
        ->and(ProductVariant::query()->find($variant->id)->is_active)->toBeFalse();
});

it('moves a product with stock history to Deactivated instead, never the Trash', function () {
    $variant = $this->makeProduct('Old Soap', '90');
    $this->receive($variant, '5', '60');

    expect($this->service->remove($variant->product))->toBe('deactivated');

    $product = Product::query()->findOrFail($variant->product_id);
    expect($product->trashed())->toBeFalse()->and($product->is_active)->toBeFalse()
        ->and(ProductVariant::query()->find($variant->id)->is_active)->toBeFalse();
});

it('keeps the SKU and barcode reserved while the product is in the Trash, and says why', function () {
    $variant = $this->makeProduct('Reserved Soap', '90');
    $sku = $variant->sku;
    $this->service->remove($variant->product);
    $pcs = Unit::query()->where('code', 'pcs')->firstOrFail();

    $create = fn () => $this->service->save([
        'name' => 'Newcomer', 'type' => 'stocked',
        'variants' => [['sku' => $sku, 'name' => 'Newcomer', 'base_unit_id' => $pcs->id, 'units' => [['unit_id' => $pcs->id, 'factor' => '1']]]],
    ]);

    expect($create)->toThrow(BusinessRuleException::class, 'Trash');
});

it('restores from the Trash and from Deactivated, active and sellable again', function () {
    $trashed = $this->makeProduct('Trashed One', '50');
    $this->service->remove($trashed->product);
    $kept = $this->makeProduct('Kept One', '50');
    $this->receive($kept, '2', '30');
    $this->service->remove($kept->product);

    $this->service->restore(Product::onlyTrashed()->findOrFail($trashed->product_id));
    $this->service->restore(Product::query()->findOrFail($kept->product_id));

    foreach ([$trashed, $kept] as $variant) {
        $product = Product::query()->findOrFail($variant->product_id);
        expect($product->trashed())->toBeFalse()->and($product->is_active)->toBeTrue()
            ->and(ProductVariant::query()->find($variant->id)->is_active)->toBeTrue();
    }
});

it('removes products after 30 days in the Trash and not before', function () {
    $recent = $this->makeProduct('Recent', '10')->product;
    $old = $this->makeProduct('Ancient', '10')->product;
    $this->service->remove($recent);
    $this->service->remove($old);
    DB::table('products')->where('id', $old->id)->update(['deleted_at' => now()->subDays(31)]);
    DB::table('products')->where('id', $recent->id)->update(['deleted_at' => now()->subDays(29)]);

    expect($this->service->purgeExpired())->toBe(1);

    expect(Product::withTrashed()->whereKey($old->id)->exists())->toBeFalse()
        ->and(Product::onlyTrashed()->whereKey($recent->id)->exists())->toBeTrue();
});

it('frees the SKU once a trashed product is deleted permanently', function () {
    $variant = $this->makeProduct('Gone Soap', '10');
    $sku = $variant->sku;
    $product = $variant->product;
    $this->service->remove($product);

    expect($this->service->purge(Product::onlyTrashed()->findOrFail($product->id)))->toBe('purged');

    $pcs = Unit::query()->where('code', 'pcs')->firstOrFail();
    $reused = $this->service->save([
        'name' => 'Reuses SKU', 'type' => 'stocked',
        'variants' => [['sku' => $sku, 'name' => 'Reuses SKU', 'base_unit_id' => $pcs->id, 'units' => [['unit_id' => $pcs->id, 'factor' => '1']]]],
    ]);
    expect($reused->variants->first()->sku)->toBe($sku);
});
