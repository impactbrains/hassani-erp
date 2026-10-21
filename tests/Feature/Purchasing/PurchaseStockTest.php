<?php

use App\Domain\Purchasing\ReceivingService;
use App\Exceptions\BusinessRuleException;
use App\Models\Contact;
use App\Models\GoodsReceipt;
use App\Models\PaymentMethod;
use App\Models\SupplierBill;
use App\Models\SupplierProfile;
use App\Models\UserPermissionOverride;
use App\Support\Settings\Settings;
use Illuminate\Support\Facades\DB;

/**
 * Purchase stock: goods received, billed and (when paid now) paid in one step through the normal
 * receiving, bills and payments logic, so stock, what is owed and the cash always agree.
 */
beforeEach(function () {
    $this->setUpCompany();
    $this->owner = $this->userWithRole('owner');
    $this->manager = $this->userWithRole('store_manager', $this->store);
    $this->supplier = Contact::create(['type' => 'supplier', 'name' => 'Jodia Bazaar Traders']);
    SupplierProfile::create(['contact_id' => $this->supplier->id, 'lead_time_days' => 1, 'payment_terms_days' => 15]);
    $this->soap = $this->makeProduct('Lux Soap', '120', ['ctn' => [24, null]]);
    $this->carton = $this->soap->units->first(fn ($u) => $u->factor === '24.000000');
    $this->cash = PaymentMethod::query()->where('code', 'cash')->firstOrFail();
    $this->purchase = fn (array $extra = []) => $extra + [
        'supplier_id' => $this->supplier->id,
        'lines' => [['variant_id' => $this->soap->id, 'variant_unit_id' => $this->carton->id, 'quantity_received' => '2', 'unit_price' => '2280']],
    ];
});

it('gives the new permission to store and inventory managers but not cashiers', function () {
    expect($this->manager->hasPermission('inventory.purchase_stock', $this->store->id))->toBeTrue()
        ->and($this->userWithRole('inventory_manager', $this->store)->hasPermission('inventory.purchase_stock', $this->store->id))->toBeTrue()
        ->and($this->userWithRole('cashier', $this->store)->hasPermission('inventory.purchase_stock', $this->store->id))->toBeFalse();
});

it('adds stock, records the bill and pays it in cash when paid now', function () {
    $receipt = app(ReceivingService::class)->receive($this->manager, $this->store, ($this->purchase)(['settlement' => 'paid_now', 'payment_method_id' => $this->cash->id]));

    expect($receipt->status)->toBe('posted')
        ->and(app(\App\Domain\Inventory\Availability::class)->available($this->store->id, $this->soap->id))->toBe('48.000');
    $bill = SupplierBill::query()->findOrFail($receipt->supplier_bill_id);
    expect($bill->total)->toBe('4560.00')
        ->and($bill->payment_status)->toBe('paid')
        ->and($receipt->payment_id)->not->toBeNull()
        ->and($this->accountBalance('inventory'))->toBe('4560.00')
        ->and($this->accountBalance('grni'))->toBe('0.00')
        ->and($this->accountBalance('accounts_payable', $this->supplier->id))->toBe('0.00')
        ->and($this->accountBalance('cash_on_hand'))->toBe('-4560.00');
    $this->assertLedgerBalanced();
});

it('adds what is owed to the supplier when bought on credit, due after the supplier\'s days to pay', function () {
    $receipt = app(ReceivingService::class)->receive($this->manager, $this->store, ($this->purchase)(['settlement' => 'on_credit']));

    $bill = SupplierBill::query()->findOrFail($receipt->supplier_bill_id);
    expect($bill->payment_status)->toBe('unpaid')
        ->and($bill->due_date->toDateString())->toBe(\Carbon\CarbonImmutable::parse($this->store->businessDate())->addDays(15)->toDateString())
        ->and($receipt->payment_id)->toBeNull()
        ->and($this->accountBalance('accounts_payable', $this->supplier->id))->toBe('-4560.00');
    $this->assertLedgerBalanced();
});

it('leaves the rest on credit when only part is paid now', function () {
    $receipt = app(ReceivingService::class)->receive($this->manager, $this->store, ($this->purchase)(['settlement' => 'paid_now', 'payment_method_id' => $this->cash->id, 'amount_paid' => '4000']));

    expect(SupplierBill::query()->find($receipt->supplier_bill_id)->payment_status)->toBe('partial')
        ->and($this->accountBalance('accounts_payable', $this->supplier->id))->toBe('-560.00');
    expect(fn () => DB::transaction(fn () => app(ReceivingService::class)->receive($this->manager, $this->store, ($this->purchase)(['settlement' => 'paid_now', 'payment_method_id' => $this->cash->id, 'amount_paid' => '9999']))))
        ->toThrow(BusinessRuleException::class, 'not more than the goods total');
});

it('needs a payment method when paid now, and the permission to buy stock', function () {
    expect(fn () => DB::transaction(fn () => app(ReceivingService::class)->receive($this->manager, $this->store, ($this->purchase)(['settlement' => 'paid_now']))))
        ->toThrow(BusinessRuleException::class, 'Choose what you paid with');
    $worker = $this->userWithRole('warehouse_worker', $this->store);
    expect(fn () => DB::transaction(fn () => app(ReceivingService::class)->receive($worker, $this->store, ($this->purchase)(['settlement' => 'on_credit']))))
        ->toThrow(BusinessRuleException::class, 'not allowed to purchase stock');
    expect(GoodsReceipt::query()->count())->toBe(0);
});

it('waits for a manager above the approval value, then posts stock, bill and payment when approved', function () {
    Settings::set('inventory.adjustment_approval_value', '1000');
    Settings::flush();
    $buyer = $this->userWithRole('warehouse_worker', $this->store);
    UserPermissionOverride::create(['user_id' => $buyer->id, 'permission' => 'inventory.purchase_stock', 'effect' => 'grant']);
    $buyer->refresh();

    $receipt = app(ReceivingService::class)->receive($buyer, $this->store, ($this->purchase)(['settlement' => 'paid_now', 'payment_method_id' => $this->cash->id]));
    expect($receipt->status)->toBe('pending_approval')
        ->and(app(\App\Domain\Inventory\Availability::class)->available($this->store->id, $this->soap->id))->toBe('0.000')
        ->and(SupplierBill::query()->count())->toBe(0);

    expect(fn () => app(ReceivingService::class)->approve($buyer, $receipt))->toThrow(BusinessRuleException::class);

    $approved = app(ReceivingService::class)->approve($this->manager, $receipt);
    expect($approved->status)->toBe('posted')
        ->and($approved->approved_by)->toBe($this->manager->id)
        ->and(app(\App\Domain\Inventory\Availability::class)->available($this->store->id, $this->soap->id))->toBe('48.000')
        ->and(SupplierBill::query()->find($approved->supplier_bill_id)->payment_status)->toBe('paid');
    $this->assertLedgerBalanced();
});

it('posts small purchases at once for someone who cannot approve, and rejected ones add nothing', function () {
    Settings::set('inventory.adjustment_approval_value', '5000');
    Settings::flush();
    $buyer = $this->userWithRole('warehouse_worker', $this->store);
    UserPermissionOverride::create(['user_id' => $buyer->id, 'permission' => 'inventory.purchase_stock', 'effect' => 'grant']);
    $buyer->refresh();

    $small = app(ReceivingService::class)->receive($buyer, $this->store, ($this->purchase)(['settlement' => 'on_credit']));
    expect($small->status)->toBe('posted');

    $big = app(ReceivingService::class)->receive($buyer, $this->store, ($this->purchase)(['settlement' => 'on_credit', 'lines' => [['variant_id' => $this->soap->id, 'variant_unit_id' => $this->carton->id, 'quantity_received' => '3', 'unit_price' => '2280']]]));
    expect($big->status)->toBe('pending_approval');
    app(ReceivingService::class)->reject($this->manager, $big, 'Wrong supplier');
    expect($big->fresh()->status)->toBe('rejected')
        ->and(app(\App\Domain\Inventory\Availability::class)->available($this->store->id, $this->soap->id))->toBe('48.000');
});

it('opens the Purchase stock screen only for people allowed to buy stock', function () {
    $this->withoutVite();
    $this->actingAs($this->manager)->get(route('purchasing.purchase-stock'))->assertOk()
        ->assertInertia(fn ($page) => $page->component('purchasing/ReceiptForm')->where('mode', 'purchase')->where('can.purchase', true)->where('approvalAbove', null));
    $this->actingAs($this->userWithRole('cashier', $this->store))->get(route('purchasing.purchase-stock'))->assertForbidden();
});

it('adds a supplier from the search box, and offers an existing one with the same name first', function () {
    $this->actingAs($this->manager)->postJson(route('purchasing.suppliers.quick'), ['name' => 'jodia bazaar traders'])
        ->assertStatus(422)->assertJsonPath('code', 'possible_duplicate');
    $this->actingAs($this->manager)->postJson(route('purchasing.suppliers.quick'), ['name' => 'Al-Madina Wholesale', 'phone' => '0300 1234567'])
        ->assertCreated()->assertJsonPath('name', 'Al-Madina Wholesale');
    expect(Contact::query()->ofType('supplier')->where('name', 'Al-Madina Wholesale')->first()?->supplierProfile)->not->toBeNull();
    $this->actingAs($this->userWithRole('cashier', $this->store))->postJson(route('purchasing.suppliers.quick'), ['name' => 'X'])->assertForbidden();
});

it('shows what was paid last time, preferring the chosen supplier', function () {
    $other = Contact::create(['type' => 'supplier', 'name' => 'Other Supplier']);
    app(ReceivingService::class)->receive($this->manager, $this->store, ($this->purchase)(['settlement' => 'on_credit']));
    app(ReceivingService::class)->receive($this->manager, $this->store, ['supplier_id' => $other->id, 'settlement' => 'on_credit',
        'lines' => [['variant_id' => $this->soap->id, 'variant_unit_id' => $this->carton->id, 'quantity_received' => '1', 'unit_price' => '2400']]]);

    $card = $this->actingAs($this->manager)->getJson(route('lookup.products', ['q' => 'Lux', 'purpose' => 'purchase', 'supplier_id' => $this->supplier->id]))->assertOk()->json('0');
    expect($card['last_cost']['pack_price'])->toBe('2280.00')
        ->and($card['last_cost']['per_unit'])->toBe('95.00')
        ->and($card['last_cost']['same_supplier'])->toBeTrue();
    $any = $this->actingAs($this->manager)->getJson(route('lookup.products', ['q' => 'Lux', 'purpose' => 'purchase']))->json('0');
    expect($any['last_cost']['pack_price'])->toBe('2400.00');
    $sale = $this->actingAs($this->manager)->getJson(route('lookup.products', ['q' => 'Lux']))->json('0');
    expect($sale)->not->toHaveKey('last_cost');
});
