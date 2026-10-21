<?php

use App\Domain\Pos\CheckoutService;
use App\Domain\Pos\PosWorkspaceService;
use App\Domain\Pos\ReceiptData;
use App\Domain\Pos\ShiftService;
use App\Exceptions\BusinessRuleException;
use App\Models\PaymentMethod;
use App\Models\Register;
use App\Models\SalesInvoice;
use App\Models\StockMovement;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->setUpCompany();
    $this->register = Register::query()->firstOrFail();
    $this->cashier = $this->userWithRole('cashier', $this->store);
    app(ShiftService::class)->open($this->cashier, $this->register, ['1000' => 2]);
    $this->cash = PaymentMethod::query()->where('code', 'cash')->firstOrFail();
    $this->soap = $this->makeProduct('Lux Soap', '120');
    $this->receive($this->soap, '2', '90');
    $this->wash = $this->makeProduct('Dish Wash', '200');
    $this->receive($this->wash, '1', '150');
    $this->line = fn ($variant, string $quantity) => ['variant_id' => $variant->id, 'variant_unit_id' => $variant->units->first()->id, 'quantity' => $quantity];
});

it('lists every item that is short in the quote, with what is needed and what is there', function () {
    $quote = app(CheckoutService::class)->quote($this->cashier, [
        'register_id' => $this->register->id,
        'lines' => [($this->line)($this->soap, '5'), ($this->line)($this->wash, '1')],
    ]);

    expect($quote['shortages'])->toHaveCount(1)
        ->and($quote['shortages'][0])->toMatchArray(['variant_id' => $this->soap->id, 'needs' => '5', 'available' => '2']);
});

it('adds the lines of one product together before checking stock', function () {
    $quote = app(CheckoutService::class)->quote($this->cashier, [
        'register_id' => $this->register->id,
        'lines' => [($this->line)($this->soap, '1'), ($this->line)($this->soap, '2')],
    ]);

    expect($quote['shortages'])->toHaveCount(1)->and($quote['shortages'][0]['needs'])->toBe('3');
});

it('refuses to sell short stock, names every short item, and posts nothing', function () {
    $sell = fn () => app(CheckoutService::class)->checkout($this->cashier, [
        'idempotency_key' => (string) Str::uuid(),
        'register_id' => $this->register->id,
        'lines' => [($this->line)($this->soap, '5'), ($this->line)($this->wash, '4')],
        'payments' => [['payment_method_id' => $this->cash->id, 'amount' => '1400']],
    ]);

    try {
        $sell();
        $this->fail('The sale should have been refused.');
    } catch (BusinessRuleException $e) {
        expect($e->errorCode)->toBe('insufficient_stock')
            ->and(collect($e->details['shortages'])->pluck('variant_id')->all())->toEqualCanonicalizing([$this->soap->id, $this->wash->id]);
    }
    expect(SalesInvoice::query()->count())->toBe(0)
        ->and(StockMovement::query()->where('movement_type', 'sale')->count())->toBe(0);
});

it('stores the sale and its receipt without any printing step', function () {
    $invoice = app(CheckoutService::class)->checkout($this->cashier, [
        'idempotency_key' => (string) Str::uuid(),
        'register_id' => $this->register->id,
        'lines' => [($this->line)($this->soap, '1')],
        'payments' => [['payment_method_id' => $this->cash->id, 'amount' => '120']],
    ]);

    // Printing happens in the browser only. The receipt can always be rebuilt from the stored sale.
    $receipt = app(ReceiptData::class)->forInvoice($invoice->fresh(), reprint: true);
    expect($receipt['number'])->toBe($invoice->number)
        ->and(SalesInvoice::query()->where('number', $invoice->number)->exists())->toBeTrue();
});

it('saves the cart tabs, drops approval tokens and keeps at most ten tabs', function () {
    $service = app(PosWorkspaceService::class);
    $carts = collect(range(1, 12))->map(fn ($n) => ['id' => "c{$n}", 'number' => $n, 'lines' => $n === 1 ? [['variant_id' => $this->soap->id, 'quantity' => '2', 'price_token' => 'secret']] : [], 'idempotencyKey' => "k{$n}"])->all();

    $service->save($this->cashier, $this->store->id, ['carts' => $carts, 'active_id' => 'c1'], null, $this->register->id);
    $saved = $service->forUser($this->cashier, $this->store->id);

    expect($saved->state['carts'])->toHaveCount(PosWorkspaceService::MAX_TABS)
        ->and($saved->state['carts'][0]['lines'][0])->not->toHaveKey('price_token')
        ->and($saved->state['carts'][0]['idempotencyKey'])->toBe('k1')
        ->and($saved->version)->toBe(1);
});

it('refuses to overwrite carts another screen saved, unless the cashier chooses to keep theirs', function () {
    $service = app(PosWorkspaceService::class);
    $state = fn (string $id) => ['carts' => [['id' => $id, 'number' => 1, 'lines' => []]], 'active_id' => $id];

    $service->save($this->cashier, $this->store->id, $state('a'), null, $this->register->id);              // screen 1 -> version 1
    $service->save($this->cashier, $this->store->id, $state('b'), 1, $this->register->id);                 // screen 1 again -> version 2
    expect(fn () => $service->save($this->cashier, $this->store->id, $state('c'), 1, $this->register->id)) // screen 2 still on version 1
        ->toThrow(BusinessRuleException::class, 'another screen');

    $result = $service->save($this->cashier, $this->store->id, $state('c'), 1, $this->register->id, force: true);
    expect($result['version'])->toBe(3)
        ->and($service->forUser($this->cashier, $this->store->id)->state['active_id'])->toBe('c');
});

it('does not let a screen that saw no carts overwrite carts another screen saved', function () {
    $service = app(PosWorkspaceService::class);
    $state = ['carts' => [['id' => 'a', 'number' => 1, 'lines' => []]], 'active_id' => 'a'];

    $service->save($this->cashier, $this->store->id, $state, null, $this->register->id);

    expect(fn () => $service->save($this->cashier, $this->store->id, $state, null, $this->register->id))
        ->toThrow(BusinessRuleException::class, 'another screen');
});
