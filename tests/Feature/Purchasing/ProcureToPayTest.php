<?php

use App\Domain\Purchasing\PayablesService;
use App\Domain\Purchasing\PurchaseOrderService;
use App\Domain\Purchasing\ReceivingService;
use App\Domain\Purchasing\ReplenishmentService;
use App\Exceptions\BusinessRuleException;
use App\Models\Contact;
use App\Models\Location;
use App\Models\PaymentMethod;
use App\Models\ReplenishmentPolicy;
use App\Models\StockBatch;
use App\Models\StockLevel;
use App\Models\SupplierProfile;
use App\Models\Unit;
use App\Models\VariantCost;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->setUpCompany();
    $this->owner = $this->userWithRole('owner');
    $this->buyer = $this->userWithRole('inventory_manager', $this->store);
    $this->supplier = Contact::create(['type' => 'supplier', 'name' => 'Karachi Distributors']);
    SupplierProfile::create(['contact_id' => $this->supplier->id, 'lead_time_days' => 2]);
    $this->soap = $this->makeProduct('Lux Soap', '120', ['ctn' => [24, null]]);
    $this->carton = $this->soap->units->first(fn ($u) => $u->factor === '24.000000');
});

it('orders, receives with landed cost, bills with a price variance and pays the supplier', function () {
    $orders = app(PurchaseOrderService::class);
    $order = $orders->save($this->buyer, $this->store, ['supplier_id' => $this->supplier->id, 'lines' => [
        ['variant_id' => $this->soap->id, 'variant_unit_id' => $this->carton->id, 'quantity' => '10', 'unit_price' => '2160'],
    ]]);
    $order = $orders->submit($this->buyer, $order);
    expect($order->status)->toBe('submitted'); // the buyer cannot approve purchases
    $order = $orders->approve($this->owner, $order);

    // Receive 6 cartons now (one damaged) with Rs 600 freight.
    $receipt = app(ReceivingService::class)->receive($this->buyer, $this->store, [
        'supplier_id' => $this->supplier->id, 'purchase_order_id' => $order->id,
        'lines' => [['variant_id' => $this->soap->id, 'variant_unit_id' => $this->carton->id, 'purchase_order_line_id' => $order->lines->first()->id,
            'quantity_received' => '6', 'quantity_damaged' => '1', 'unit_price' => '2160']],
        'landed_costs' => [['type' => 'freight', 'amount' => '600']],
    ]);
    $line = $receipt->lines->first();
    expect($line->base_unit_cost)->toBe('94.166667') // (6 x 2160 + 600) / 144
        ->and($order->fresh()->status)->toBe('partially_received');
    $damaged = Location::query()->where('type', 'damaged')->value('id');
    expect(StockLevel::query()->where('location_id', $damaged)->sum('on_hand'))->toEqual('24.000');
    expect($this->accountBalance('inventory'))->toBe('13560.00')
        ->and($this->accountBalance('grni'))->toBe('-13560.00');

    // The supplier bills Rs 2200 a carton (Rs 40 more than ordered).
    $bill = app(PayablesService::class)->postBill($this->owner, $this->store, ['supplier_id' => $this->supplier->id, 'bill_date' => now()->toDateString(), 'lines' => [
        ['goods_receipt_line_id' => $line->id, 'quantity' => '6', 'unit_price' => '2200'],
    ]]);
    expect($bill->total)->toBe('13200.00');
    expect($this->accountBalance('purchase_price_variance'))->toBe('240.00')
        ->and($this->accountBalance('grni'))->toBe('-600.00') // freight accrual waits for the transporter's bill
        ->and($this->accountBalance('accounts_payable', $this->supplier->id))->toBe('-13200.00');

    expect(fn () => DB::transaction(fn () => app(PayablesService::class)->postBill($this->owner, $this->store, ['supplier_id' => $this->supplier->id, 'bill_date' => now()->toDateString(), 'lines' => [
        ['goods_receipt_line_id' => $line->id, 'quantity' => '1', 'unit_price' => '2200'],
    ]])))->toThrow(BusinessRuleException::class, 'not yet billed');

    $cash = PaymentMethod::query()->where('code', 'bank_transfer')->firstOrFail();
    app(PayablesService::class)->paySupplier($this->owner, $this->store, ['supplier_id' => $this->supplier->id, 'payment_method_id' => $cash->id, 'amount' => '10000', 'reference' => 'IBFT-1']);
    expect($bill->fresh()->payment_status)->toBe('partial')
        ->and($this->accountBalance('accounts_payable', $this->supplier->id))->toBe('-3200.00');
    $this->assertLedgerBalanced();
});

it('puts goods on QC hold out of sellable stock and returns damaged goods to the supplier', function () {
    $receipt = app(ReceivingService::class)->receive($this->buyer, $this->store, [
        'supplier_id' => $this->supplier->id,
        'lines' => [['variant_id' => $this->soap->id, 'variant_unit_id' => $this->carton->id, 'quantity_received' => '2', 'unit_price' => '2400',
            'batch_no' => 'LX-2026-10', 'expiry_date' => now()->addYear()->toDateString(), 'quality_status' => 'quarantined']],
    ]);
    expect(StockBatch::query()->first()->status)->toBe('quarantined');
    expect(app(\App\Domain\Inventory\Availability::class)->available($this->store->id, $this->soap->id))->toBe('0.000');

    $qc = Location::query()->where('type', 'quarantine')->value('id');
    app(PayablesService::class)->returnToSupplier($this->owner, $this->store, ['supplier_id' => $this->supplier->id, 'lines' => [
        ['goods_receipt_line_id' => $receipt->lines->first()->id, 'location_id' => $qc, 'base_quantity' => '24'],
    ]]);
    expect(StockLevel::query()->where('location_id', $qc)->sum('on_hand'))->toEqual('24.000');
    expect($this->accountBalance('grni'))->toBe('-2400.00');
    $this->assertLedgerBalanced();
});

it('refuses to receive more than is on order', function () {
    $orders = app(PurchaseOrderService::class);
    $order = $orders->approve($this->owner, $orders->submit($this->buyer, $orders->save($this->buyer, $this->store, ['supplier_id' => $this->supplier->id, 'lines' => [
        ['variant_id' => $this->soap->id, 'variant_unit_id' => $this->carton->id, 'quantity' => '1', 'unit_price' => '2160'],
    ]])));

    expect(fn () => DB::transaction(fn () => app(ReceivingService::class)->receive($this->buyer, $this->store, [
        'supplier_id' => $this->supplier->id, 'purchase_order_id' => $order->id,
        'lines' => [['variant_id' => $this->soap->id, 'variant_unit_id' => $this->carton->id, 'purchase_order_line_id' => $order->lines->first()->id, 'quantity_received' => '2', 'unit_price' => '2160']],
    ])))->toThrow(BusinessRuleException::class, 'still on order');
});

it('suggests reorders in whole cartons from recent demand and lead time', function () {
    $this->receive($this->soap, '30', '90');
    DB::transaction(fn () => app(\App\Domain\Inventory\StockLedger::class)->post([
        new \App\Domain\Inventory\StockChange($this->store->id, $this->store->defaultSellingLocation()->id, $this->soap->id, '-28', 'sale'),
    ], new \App\Domain\Inventory\StockContext('test', null, null, now()->toDateString())));
    ReplenishmentPolicy::create(['store_id' => $this->store->id, 'variant_id' => $this->soap->id, 'min_quantity' => '10', 'safety_stock' => '5', 'preferred_supplier_id' => $this->supplier->id]);
    \App\Models\SupplierProduct::create(['supplier_id' => $this->supplier->id, 'variant_id' => $this->soap->id, 'variant_unit_id' => $this->carton->id, 'is_preferred' => true]);

    $rows = app(ReplenishmentService::class)->suggestions($this->store->id);
    expect($rows)->toHaveCount(1);
    expect($rows[0]['available'])->toBe('2.000')
        ->and(fmod((float) $rows[0]['suggested_quantity'], 24.0))->toBe(0.0)
        ->and($rows[0]['supplier_name'])->toBe('Karachi Distributors');
});
