<?php

use App\Domain\Crm\CustomerService;
use App\Domain\Crm\KhataService;
use App\Domain\Hr\EmployeeService;
use App\Domain\Inventory\Availability;
use App\Domain\Pos\ShiftService;
use App\Domain\Sales\CollectionService;
use App\Domain\Sales\DeliveryService;
use App\Domain\Sales\PaymentVerificationService;
use App\Domain\Sales\SalesOrderService;
use App\Exceptions\BusinessRuleException;
use App\Models\Contact;
use App\Models\Delivery;
use App\Models\PaymentMethod;
use App\Models\PaymentPromise;
use App\Models\ReasonCode;
use App\Models\Register;
use App\Models\StockReservation;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->setUpCompany();
    $this->manager = $this->userWithRole('store_manager', $this->store);
    $this->owner = $this->userWithRole('owner');
    $this->soap = $this->makeProduct('Lux Soap', '100');
    $this->receive($this->soap, '50', '70');
    $this->customer = app(CustomerService::class)->save($this->owner, ['name' => 'Al-Madina Store', 'phone' => '0321-5550000', 'category' => 'wholesale', 'credit_limit' => '20000', 'payment_terms_days' => 15]);
    $this->orders = app(SalesOrderService::class);
    $this->order = fn (string $qty = '10', array $extra = []) => $this->orders->save($this->manager, $this->store, array_merge([
        'customer_id' => $this->customer->id, 'channel' => 'phone', 'fulfillment_type' => 'delivery', 'payment_preference' => 'cod',
        'lines' => [['variant_id' => $this->soap->id, 'variant_unit_id' => $this->soap->units->first()->id, 'quantity' => $qty]],
    ], $extra));
});

it('reserves stock on confirmation so the till cannot sell it', function () {
    $order = $this->orders->confirm($this->manager, ($this->order)('45'));
    expect($order->status)->toBe('confirmed');
    expect(app(Availability::class)->available($this->store->id, $this->soap->id))->toBe('5.000');

    // A second order for more than what is left is refused.
    expect(fn () => DB::transaction(fn () => $this->orders->confirm($this->manager, ($this->order)('6'))))->toThrow(BusinessRuleException::class, 'Not enough');

    // Cancelling releases the reservation.
    $this->orders->cancel($this->manager, $order, ReasonCode::query()->where('type', 'order_cancel')->value('id'));
    expect(app(Availability::class)->available($this->store->id, $this->soap->id))->toBe('50.000');
});

it('dispatches to a rider, collects cash on delivery and reconciles the rider handover into the drawer', function () {
    $rider = app(EmployeeService::class)->save($this->owner, ['name' => 'Imran Rider', 'role_type' => 'rider']);
    $order = $this->orders->confirm($this->manager, ($this->order)('10', ['delivery_charge' => '100']));
    $invoice = $this->orders->dispatch($this->manager, $order, $rider->id);

    expect($invoice->total)->toBe('1100.00')->and($invoice->payment_status)->toBe('unpaid');
    expect(StockReservation::query()->where('status', 'active')->count())->toBe(0);
    expect($this->accountBalance('accounts_receivable', $this->customer->id))->toBe('1100.00');

    $delivery = Delivery::query()->firstOrFail();
    app(DeliveryService::class)->markDelivered($this->manager, $delivery, '1100', 'Signed by owner');
    expect($invoice->fresh()->payment_status)->toBe('paid');
    expect($this->accountBalance('cash_with_riders'))->toBe('1100.00')
        ->and(app(DeliveryService::class)->cashWithRider($rider->id))->toBe('1100.00');

    $cashier = $this->userWithRole('cashier', $this->store);
    $shift = app(ShiftService::class)->open($cashier, Register::query()->first(), ['1000' => 1]);
    $handover = app(DeliveryService::class)->handover($this->manager, $this->store->id, $rider->id, '1080', $shift->id, 'Rs 20 short');

    expect($handover->variance)->toBe('-20.00');
    expect($this->accountBalance('cash_with_riders'))->toBe('0.00')
        ->and($this->accountBalance('cash_over_short'))->toBe('20.00');
    expect(app(ShiftService::class)->xReport($cashier, $shift)['cash']['expected'])->toBe('2080.00');
    $this->assertLedgerBalanced();
});

it('collects Wasooli oldest invoice first, keeps promises honest and matches the statement', function () {
    foreach (['3', '2'] as $qty) {
        $order = $this->orders->confirm($this->manager, ($this->order)($qty, ['fulfillment_type' => 'pickup', 'payment_preference' => 'khata']));
        $this->orders->dispatch($this->manager, $order);
    }
    PaymentPromise::create(['contact_id' => $this->customer->id, 'amount' => '350', 'promised_date' => now()->addDay()->toDateString()]);
    $cash = PaymentMethod::query()->where('code', 'cash')->firstOrFail();

    app(CollectionService::class)->receive($this->manager, $this->store, ['customer_id' => $this->customer->id, 'payment_method_id' => $cash->id, 'amount' => '350']);

    $invoices = \App\Models\SalesInvoice::query()->orderBy('id')->get();
    expect($invoices[0]->payment_status)->toBe('paid')->and($invoices[1]->balanceDue())->toBe('150.00');
    expect(PaymentPromise::query()->first()->status)->toBe('kept');

    $statement = app(KhataService::class)->statement($this->customer, now()->subMonth()->toDateString(), now()->addDay()->toDateString());
    expect($statement['closing'])->toBe('150.00')
        ->and($statement['closing'])->toBe(app(\App\Domain\Sales\CreditControl::class)->balance($this->customer->id));
    $this->assertLedgerBalanced();
});

it('puts an invoice back on Khata when a wallet payment is rejected', function () {
    $order = $this->orders->confirm($this->manager, ($this->order)('2', ['fulfillment_type' => 'pickup', 'payment_preference' => 'khata']));
    $invoice = $this->orders->dispatch($this->manager, $order);
    $jazz = PaymentMethod::query()->where('code', 'jazzcash')->firstOrFail();
    $payment = app(CollectionService::class)->receive($this->manager, $this->store, ['customer_id' => $this->customer->id, 'payment_method_id' => $jazz->id, 'amount' => '200', 'reference' => 'JC-998']);
    expect($payment->verification_status)->toBe('pending')->and($invoice->fresh()->payment_status)->toBe('paid');

    app(PaymentVerificationService::class)->reject($this->owner, $payment, 'Not in JazzCash statement');

    expect($invoice->fresh()->payment_status)->toBe('unpaid');
    expect($this->accountBalance('accounts_receivable', $this->customer->id))->toBe('200.00')
        ->and($this->accountBalance('wallet_jazzcash'))->toBe('0.00');
});

it('detects duplicate customers and merges them with their Khata', function () {
    expect(fn () => app(CustomerService::class)->save($this->owner, ['name' => 'Al Madina Store', 'phone' => '+92 321 5550000']))
        ->toThrow(BusinessRuleException::class, 'already exists');

    $duplicate = app(CustomerService::class)->save($this->owner, ['name' => 'Madina Traders', 'phone' => '0300-0000001', 'credit_limit' => '5000', 'confirm_not_duplicate' => true]);
    $order = $this->orders->confirm($this->manager, $this->orders->save($this->manager, $this->store, [
        'customer_id' => $duplicate->id, 'payment_preference' => 'khata',
        'lines' => [['variant_id' => $this->soap->id, 'variant_unit_id' => $this->soap->units->first()->id, 'quantity' => '1']],
    ]));
    $this->orders->dispatch($this->manager, $order);

    app(CustomerService::class)->merge($this->owner, $this->customer, $duplicate);
    expect($this->accountBalance('accounts_receivable', $this->customer->id))->toBe('100.00')
        ->and($this->accountBalance('accounts_receivable', $duplicate->id))->toBe('0.00')
        ->and(\App\Models\SalesInvoice::query()->where('customer_id', $this->customer->id)->count())->toBe(1)
        ->and($duplicate->fresh()->merged_into_id)->toBe($this->customer->id);
});
