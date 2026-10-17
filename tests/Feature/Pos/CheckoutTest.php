<?php

use App\Domain\Approvals\ApprovalService;
use App\Domain\Pos\CheckoutService;
use App\Domain\Pos\ShiftService;
use App\Exceptions\BusinessRuleException;
use App\Models\Contact;
use App\Models\CustomerProfile;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Register;
use App\Models\SalesInvoice;
use App\Models\StockMovement;
use App\Support\Settings\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->setUpCompany();
    $this->register = Register::query()->firstOrFail();
    $this->cashier = $this->userWithRole('cashier', $this->store);
    $this->manager = $this->userWithRole('store_manager', $this->store, '4321');
    $this->shift = app(ShiftService::class)->open($this->cashier, $this->register, ['1000' => 2]);
    $this->cash = PaymentMethod::query()->where('code', 'cash')->firstOrFail();
    $this->easypaisa = PaymentMethod::query()->where('code', 'easypaisa')->firstOrFail();
    $this->soap = $this->makeProduct('Lux Soap', '120');
    $this->receive($this->soap, '10', '90');
    $this->checkout = fn (array $overrides = [], $user = null) => app(CheckoutService::class)->checkout($user ?? $this->cashier, array_merge([
        'idempotency_key' => (string) Str::uuid(),
        'register_id' => $this->register->id,
        'lines' => [['variant_id' => $this->soap->id, 'variant_unit_id' => $this->soap->units->first()->id, 'quantity' => '2']],
        'payments' => [['payment_method_id' => $this->cash->id, 'amount' => '240', 'tendered' => '500']],
    ], $overrides));
});

it('sells, takes stock out, records change and posts balanced journals', function () {
    $invoice = ($this->checkout)();

    expect($invoice->total)->toBe('240.00')
        ->and($invoice->payment_status)->toBe('paid')
        ->and($invoice->cost_total)->toBe('180.00');
    expect(Payment::query()->first()->change_amount)->toBe('260.00');
    expect(StockMovement::query()->where('movement_type', 'sale')->sum('quantity'))->toEqual('-2.000');
    expect($this->accountBalance('sales'))->toBe('-240.00')
        ->and($this->accountBalance('cogs'))->toBe('180.00')
        ->and($this->accountBalance('cash_on_hand'))->toBe('240.00');
    $this->assertLedgerBalanced();
});

it('returns the same receipt when the same checkout is retried', function () {
    $key = (string) Str::uuid();
    $first = ($this->checkout)(['idempotency_key' => $key]);
    $second = ($this->checkout)(['idempotency_key' => $key]);

    expect($second->id)->toBe($first->id)
        ->and(SalesInvoice::query()->count())->toBe(1)
        ->and(StockMovement::query()->where('movement_type', 'sale')->count())->toBe(1);
});

it('splits tender across cash, Easypaisa and Khata within the credit limit', function () {
    $customer = Contact::create(['type' => 'customer', 'name' => 'Ahmed Khan', 'phone' => '0300-1234567']);
    CustomerProfile::create(['contact_id' => $customer->id, 'credit_limit' => '5000']);

    $invoice = ($this->checkout)([
        'customer_id' => $customer->id,
        'lines' => [['variant_id' => $this->soap->id, 'variant_unit_id' => $this->soap->units->first()->id, 'quantity' => '5']],
        'payments' => [
            ['payment_method_id' => $this->cash->id, 'amount' => '200'],
            ['payment_method_id' => $this->easypaisa->id, 'amount' => '150', 'reference' => 'EP123456'],
        ],
    ]);

    expect($invoice->total)->toBe('600.00')
        ->and($invoice->credit_extended)->toBe('250.00')
        ->and($invoice->payment_status)->toBe('partial');
    expect(Payment::query()->where('payment_method_id', $this->easypaisa->id)->first()->verification_status)->toBe('pending');
    expect($this->accountBalance('accounts_receivable', $customer->id))->toBe('250.00');
    $this->assertLedgerBalanced();
});

it('blocks Khata above the limit unless a manager approves with their PIN', function () {
    $customer = Contact::create(['type' => 'customer', 'name' => 'Bilal']);
    CustomerProfile::create(['contact_id' => $customer->id, 'credit_limit' => '100']);
    $sale = fn ($token = null) => ($this->checkout)(['customer_id' => $customer->id, 'payments' => [], 'credit_approval_token' => $token]);

    expect(fn () => DB::transaction(fn () => $sale()))->toThrow(BusinessRuleException::class, 'Manager approval needed');

    $grant = app(ApprovalService::class)->grant($this->cashier, $this->manager, '4321', 'credit_limit', $this->store->id);
    $invoice = $sale($grant['token']);
    expect($invoice->credit_extended)->toBe('240.00');

    // The token is single use.
    expect(fn () => DB::transaction(fn () => $sale($grant['token'])))->toThrow(BusinessRuleException::class, 'already used');
});

it('needs a manager PIN for discounts above the cashier limit', function () {
    Settings::set('pricing.max_discount_percent', '5');
    $discounted = fn ($token = null) => ($this->checkout)([
        'cart_discount' => ['type' => 'percent', 'value' => '10'],
        'payments' => [['payment_method_id' => $this->cash->id, 'amount' => '216']],
        'discount_approval_token' => $token,
    ]);

    expect(fn () => DB::transaction(fn () => $discounted()))->toThrow(BusinessRuleException::class);

    $grant = app(ApprovalService::class)->grant($this->cashier, $this->manager, '4321', 'discount', $this->store->id, ['max_discount_percent' => '10']);
    $invoice = $discounted($grant['token']);
    expect($invoice->discount_total)->toBe('24.00')->and($invoice->total)->toBe('216.00');
});

it('rejects a wrong manager PIN', function () {
    expect(fn () => app(ApprovalService::class)->grant($this->cashier, $this->manager, '0000', 'discount', $this->store->id))
        ->toThrow(BusinessRuleException::class, 'PIN is not correct');
});

it('refuses a price override without approval and accepts it with one', function () {
    $override = fn ($token = null) => ($this->checkout)([
        'lines' => [['variant_id' => $this->soap->id, 'variant_unit_id' => $this->soap->units->first()->id, 'quantity' => '1', 'unit_price' => '110', 'price_approval_token' => $token]],
        'payments' => [['payment_method_id' => $this->cash->id, 'amount' => '110']],
    ]);

    expect(fn () => DB::transaction(fn () => $override()))->toThrow(BusinessRuleException::class, 'manager approval');

    $grant = app(ApprovalService::class)->grant($this->cashier, $this->manager, '4321', 'price_override', $this->store->id);
    $invoice = $override($grant['token']);
    expect($invoice->lines->first()->price_overridden)->toBeTrue()
        ->and($invoice->lines->first()->list_price)->toBe('120.00');
});

it('refuses to sell more than is in stock', function () {
    expect(fn () => DB::transaction(fn () => ($this->checkout)([
        'lines' => [['variant_id' => $this->soap->id, 'variant_unit_id' => $this->soap->units->first()->id, 'quantity' => '11']],
        'payments' => [['payment_method_id' => $this->cash->id, 'amount' => '1320']],
    ])))->toThrow(BusinessRuleException::class, 'Not enough');
});

it('applies cash rounding as a separate adjustment', function () {
    Settings::set('pricing.rounding_step', '5', 'store', $this->store->id);
    $biscuit = $this->makeProduct('Biscuit', '33');
    $this->receive($biscuit, '5', '20');

    $invoice = ($this->checkout)([
        'lines' => [['variant_id' => $biscuit->id, 'variant_unit_id' => $biscuit->units->first()->id, 'quantity' => '1']],
        'payments' => [['payment_method_id' => $this->cash->id, 'amount' => '35']],
    ]);

    expect($invoice->total)->toBe('35.00')->and($invoice->rounding_adjustment)->toBe('2.00');
    expect($this->accountBalance('rounding'))->toBe('-2.00');
    $this->assertLedgerBalanced();
});

it('sells packs and loose quantities in the right base units', function () {
    $sugar = $this->makeProduct('Sugar', '150', [], []);
    $kg = \App\Models\Unit::query()->where('code', 'kg')->firstOrFail();
    $loose = app(\App\Domain\Catalog\ProductService::class)->save([
        'name' => 'Daal Chana', 'type' => 'stocked',
        'variants' => [['sku' => 'DAAL-LOOSE', 'name' => 'Loose', 'base_unit_id' => $kg->id, 'is_loose' => true,
            'units' => [['unit_id' => $kg->id, 'factor' => '1', 'is_default_sale' => true]],
            'prices' => [['price_book_id' => \App\Models\PriceBook::query()->where('code', 'RETAIL')->value('id'), 'unit_id' => $kg->id, 'price' => '320']]]],
    ])->variants->first()->load('product', 'units.unit');
    $this->receive($loose, '50', '250');

    $invoice = ($this->checkout)([
        'lines' => [['variant_id' => $loose->id, 'variant_unit_id' => $loose->units->first()->id, 'quantity' => '1.75']],
        'payments' => [['payment_method_id' => $this->cash->id, 'amount' => '560']],
    ]);
    expect($invoice->total)->toBe('560.00');

    $tea = $this->makeProduct('Tea sachet', '10', ['ladi' => [12, '110']]);
    $this->receive($tea, '24', '7');
    $ladi = $tea->units->firstWhere('factor', '12.000000');
    $invoice = ($this->checkout)([
        'lines' => [['variant_id' => $tea->id, 'variant_unit_id' => $ladi->id, 'quantity' => '1']],
        'payments' => [['payment_method_id' => $this->cash->id, 'amount' => '110']],
    ]);
    expect($invoice->lines->first()->base_quantity)->toBe('12.000');
    expect(\App\Models\StockLevel::query()->where('variant_id', $tea->id)->sum('on_hand'))->toEqual('12.000');
});

it('refuses whole-number units with fractions', function () {
    expect(fn () => DB::transaction(fn () => ($this->checkout)([
        'lines' => [['variant_id' => $this->soap->id, 'variant_unit_id' => $this->soap->units->first()->id, 'quantity' => '1.5']],
    ])))->toThrow(BusinessRuleException::class, 'whole numbers');
});

it('blocks selling a product that has no price', function () {
    $noPrice = $this->makeProduct('Mystery', '1');
    \App\Models\Price::query()->where('variant_id', $noPrice->id)->delete();
    $this->receive($noPrice, '3', '10');

    expect(fn () => DB::transaction(fn () => ($this->checkout)([
        'lines' => [['variant_id' => $noPrice->id, 'variant_unit_id' => $noPrice->units->first()->id, 'quantity' => '1']],
        'payments' => [['payment_method_id' => $this->cash->id, 'amount' => '10']],
    ])))->toThrow(BusinessRuleException::class, 'has no price');
});

it('requires an open shift owned by the cashier', function () {
    $other = $this->userWithRole('cashier', $this->store);

    expect(fn () => DB::transaction(fn () => ($this->checkout)([], $other)))->toThrow(BusinessRuleException::class, 'another cashier');
});
