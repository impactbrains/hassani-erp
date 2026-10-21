<?php

use App\Domain\Crm\CustomerService;
use App\Domain\Hr\EmployeeService;
use App\Domain\Hr\PayrollService;
use App\Domain\Inventory\AdjustmentService;
use App\Domain\Inventory\CountService;
use App\Domain\Inventory\TransferService;
use App\Domain\Pos\CheckoutService;
use App\Domain\Pos\ShiftService;
use App\Domain\Purchasing\PayablesService;
use App\Domain\Purchasing\PurchaseOrderService;
use App\Domain\Purchasing\ReceivingService;
use App\Domain\Sales\SalesOrderService;
use App\Domain\Setup\CompanyProvisioner;
use App\Models\Contact;
use App\Models\JournalEntry;
use App\Models\PaymentMethod;
use App\Models\Register;
use App\Models\SalaryStructure;
use App\Models\SupplierProfile;
use Illuminate\Support\Str;

/**
 * Every record page that has next / previous arrows opens, with real records, from the list screen it
 * belongs to. Two records of each kind, so the arrows point at the other one.
 */
beforeEach(function () {
    $this->setUpCompany();
    $this->owner = $this->userWithRole('owner');
    $this->cashier = $this->userWithRole('cashier', $this->store);
    $this->supplier = Contact::create(['type' => 'supplier', 'name' => 'Karachi Distributors']);
    SupplierProfile::create(['contact_id' => $this->supplier->id, 'lead_time_days' => 2]);
    $this->soap = $this->makeProduct('Lux Soap', '120');
    $this->receive($this->soap, '50', '90');
    $this->unit = $this->soap->units->first();
    $this->floor = $this->store->defaultSellingLocation();

    /** Opens the list (with a filter that matches both records) and then the first record; checks the arrows point at the other one. */
    $this->arrowsBetween = function (string $module, array $listFilters, $first, $second, string $show = 'show') {
        $this->actingAs($this->owner)->get(route("{$module}.index", $listFilters))->assertOk();
        $this->actingAs($this->owner)->get(route("{$module}.{$show}", $first->id))->assertOk()
            ->assertInertia(fn ($page) => $page->where('pager.total', 2)->where('pager.position', 1)
                ->where('pager.next', route("{$module}.{$show}", $second->id))->where('pager.prev', route("{$module}.{$show}", $second->id)));
    };
});

it('has arrows on receipts and sales invoices (batch 1)', function () {
    $register = Register::query()->firstOrFail();
    app(ShiftService::class)->open($this->cashier, $register, ['1000' => 2]);
    $cash = PaymentMethod::query()->where('code', 'cash')->firstOrFail();
    $sell = fn () => app(CheckoutService::class)->checkout($this->cashier, [
        'idempotency_key' => (string) Str::uuid(), 'register_id' => $register->id,
        'lines' => [['variant_id' => $this->soap->id, 'variant_unit_id' => $this->unit->id, 'quantity' => '1']],
        'payments' => [['payment_method_id' => $cash->id, 'amount' => '120', 'tendered' => '120']],
    ]);
    $first = $sell();
    $second = $sell();

    // Receipts list, newest first: the second sale is position 1.
    ($this->arrowsBetween)('sales.invoices', ['payment_status' => 'paid'], $second, $first);

    // The same checkouts posted journal entries, and the shift is on screen too.
    $entries = JournalEntry::query()->where('status', 'posted')->orderByDesc('id')->limit(2)->get();
    $this->actingAs($this->owner)->get(route('accounting.journals.index'))->assertOk();
    $this->actingAs($this->owner)->get(route('accounting.journals.show', $entries[0]->id))->assertOk()
        ->assertInertia(fn ($page) => $page->where('pager.position', 1)->where('pager.next', route('accounting.journals.show', $entries[1]->id)));
    $this->actingAs($this->owner)->get(route('pos.shifts.index'))->assertOk();
    $this->actingAs($this->owner)->get(route('pos.shifts.show', $second->shift_id))->assertOk()->assertInertia(fn ($page) => $page->where('pager.total', 1));
});

it('has arrows on sales orders and customers (batch 1)', function () {
    $customer = app(CustomerService::class)->save($this->owner, ['name' => 'Al-Madina Store', 'phone' => '0321-5550000', 'category' => 'wholesale', 'credit_limit' => '20000', 'payment_terms_days' => 15]);
    $other = app(CustomerService::class)->save($this->owner, ['name' => 'Zia Store', 'phone' => '0321-5550001']);
    $order = fn () => app(SalesOrderService::class)->save($this->owner, $this->store, [
        'customer_id' => $customer->id, 'channel' => 'phone', 'fulfillment_type' => 'delivery', 'payment_preference' => 'cod',
        'lines' => [['variant_id' => $this->soap->id, 'variant_unit_id' => $this->unit->id, 'quantity' => '2']],
    ]);
    $a = $order();
    $b = $order();

    ($this->arrowsBetween)('sales.orders', [], $b, $a);
    ($this->arrowsBetween)('crm.customers', ['q' => 'Store'], $customer, $other);
});

it('has arrows on purchase orders, goods received and bills (batch 1 and 2)', function () {
    $orders = app(PurchaseOrderService::class);
    $make = fn () => $orders->save($this->owner, $this->store, ['supplier_id' => $this->supplier->id, 'lines' => [['variant_id' => $this->soap->id, 'variant_unit_id' => $this->unit->id, 'quantity' => '10', 'unit_price' => '90']]]);
    $po1 = $make();
    $po2 = $make();
    ($this->arrowsBetween)('purchasing.orders', ['supplier_id' => $this->supplier->id], $po2, $po1);

    $receive = fn () => app(ReceivingService::class)->receive($this->owner, $this->store, ['supplier_id' => $this->supplier->id, 'lines' => [['variant_id' => $this->soap->id, 'variant_unit_id' => $this->unit->id, 'quantity_received' => '5', 'unit_price' => '90']]]);
    $r1 = $receive();
    $r2 = $receive();
    ($this->arrowsBetween)('purchasing.receipts', ['status' => 'posted'], $r2, $r1);

    $bill = fn ($receipt) => app(PayablesService::class)->postBill($this->owner, $this->store, ['supplier_id' => $this->supplier->id, 'bill_date' => now()->toDateString(), 'lines' => [['goods_receipt_line_id' => $receipt->lines->first()->id, 'quantity' => '5', 'unit_price' => '90']]]);
    $b1 = $bill($r1);
    $b2 = $bill($r2);
    $this->actingAs($this->owner)->get(route('purchasing.bills'))->assertOk();
    $this->actingAs($this->owner)->get(route('purchasing.bills.show', $b2->id))->assertOk()
        ->assertInertia(fn ($page) => $page->where('pager.total', 2)->where('pager.next', route('purchasing.bills.show', $b1->id)));
});

it('has arrows on adjustments, transfers and counts (batch 2)', function () {
    $adjust = fn () => app(AdjustmentService::class)->create($this->owner, $this->store, 'opening', [['variant_id' => $this->soap->id, 'location_id' => $this->floor->id, 'quantity' => '5', 'unit_cost' => '90']], null, null);
    $a1 = $adjust();
    $a2 = $adjust();
    ($this->arrowsBetween)('inventory.adjustments', ['kind' => 'opening'], $a2, $a1);

    $second = app(CompanyProvisioner::class)->createStore('Branch 2', 'B2');
    $move = fn () => app(TransferService::class)->create($this->owner, $this->floor->id, $second->defaultReceivingLocation()->id, [['variant_id' => $this->soap->id, 'quantity' => '1']], null);
    $t1 = $move();
    $t2 = $move();
    ($this->arrowsBetween)('inventory.transfers', [], $t2, $t1);

    $counts = app(CountService::class);
    $c1 = $counts->plan($this->owner, $this->store, 'full', [], true, false, null, null);
    $c2 = $counts->plan($this->owner, $this->store, 'full', [], true, false, null, null);
    ($this->arrowsBetween)('inventory.counts', [], $c2, $c1);
});

it('has arrows on employees and payroll runs (batch 2)', function () {
    $employees = app(EmployeeService::class);
    $one = $employees->save($this->owner, ['name' => 'Ayesha Khan', 'role_type' => 'cashier', 'store_id' => $this->store->id, 'joining_date' => '2026-01-01']);
    $two = $employees->save($this->owner, ['name' => 'Bilal Ahmed', 'role_type' => 'cashier', 'store_id' => $this->store->id, 'joining_date' => '2026-01-01']);
    ($this->arrowsBetween)('hr.employees', ['status' => 'current'], $one, $two);

    SalaryStructure::create(['contact_id' => $one->id, 'effective_from' => '2026-01-01', 'pay_basis' => 'monthly', 'base_amount' => '31000', 'components' => []]);
    $payroll = app(PayrollService::class);
    $september = $payroll->prepare($this->owner, '2026-09-01');
    $october = $payroll->prepare($this->owner, '2026-10-01');
    $this->actingAs($this->owner)->get(route('hr.payroll.index'))->assertOk();
    $this->actingAs($this->owner)->get(route('hr.payroll.show', $october->id))->assertOk()
        ->assertInertia(fn ($page) => $page->where('pager.position', 1)->where('pager.total', 2)->where('pager.next', route('hr.payroll.show', $september->id)));
});
