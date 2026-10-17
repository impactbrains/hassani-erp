<?php

use App\Domain\Catalog\UnlistedItems;
use App\Domain\Pos\CheckoutService;
use App\Domain\Pos\ShiftService;
use App\Exceptions\BusinessRuleException;
use App\Models\PaymentMethod;
use App\Models\PendingProduct;
use App\Models\Register;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->setUpCompany();
    $this->register = Register::query()->firstOrFail();
    $this->cashier = $this->userWithRole('cashier', $this->store);
    app(ShiftService::class)->open($this->cashier, $this->register, ['1000' => 1]);
    $this->item = app(UnlistedItems::class)->variant();
    $this->sell = fn (string $name, string $price, string $qty = '1', ?string $barcode = null) => app(CheckoutService::class)->checkout($this->cashier, [
        'idempotency_key' => (string) Str::uuid(), 'register_id' => $this->register->id,
        'lines' => [['variant_id' => $this->item->id, 'variant_unit_id' => $this->item->units->first()->id, 'quantity' => $qty, 'unit_price' => $price, 'description' => $name, 'barcode' => $barcode]],
        'payments' => [['payment_method_id' => PaymentMethod::query()->where('code', 'cash')->value('id'), 'amount' => \App\Support\Decimal::money(\App\Support\Decimal::mul($price, $qty))]],
    ]);
});

it('sells an item that is not in the product list without touching stock', function () {
    $invoice = ($this->sell)('Peek Freans Sooper 12-pack', '240', '2', '8964000123456');

    expect($invoice->total)->toBe('480.00')
        ->and($invoice->lines->first()->description)->toBe('*Peek Freans Sooper 12-pack')
        ->and(StockMovement::query()->count())->toBe(0);
    $this->assertLedgerBalanced();

    $pending = PendingProduct::query()->sole();
    expect($pending->name)->toBe('Peek Freans Sooper 12-pack')
        ->and($pending->barcode)->toBe('8964000123456')
        ->and($pending->times_sold)->toBe(1)
        ->and($pending->quantity_sold)->toEqual('2.000');
});

it('groups repeat sales of the same unlisted item and rates how often it sells', function () {
    foreach (range(1, 5) as $i) {
        ($this->sell)($i % 2 ? 'Rooh Afza 800ml' : '  rooh afza   800ml ', '450');
    }
    $pending = PendingProduct::query()->sole();

    expect($pending->times_sold)->toBe(5)
        ->and($pending->total_sales)->toEqual('2250.00')
        ->and(UnlistedItems::frequency($pending->sales()->where('sold_at', '>=', now()->subDays(30))->count()))->toBe('frequently')
        ->and(UnlistedItems::frequency(3))->toBe('occasionally')
        ->and(UnlistedItems::frequency(1))->toBe('rarely');
});

it('needs a name and a price for an unlisted item', function () {
    expect(fn () => DB::transaction(fn () => ($this->sell)('', '100')))->toThrow(BusinessRuleException::class, 'name');
    expect(fn () => DB::transaction(fn () => ($this->sell)('Some item', '0')))->toThrow(BusinessRuleException::class, 'price');
});
