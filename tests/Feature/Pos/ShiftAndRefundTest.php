<?php

use App\Domain\Approvals\ApprovalService;
use App\Domain\Pos\CheckoutService;
use App\Domain\Pos\HeldCartService;
use App\Domain\Pos\ShiftService;
use App\Domain\Sales\CreditNoteService;
use App\Exceptions\BusinessRuleException;
use App\Models\Contact;
use App\Models\CustomerProfile;
use App\Models\Location;
use App\Models\PaymentMethod;
use App\Models\ReasonCode;
use App\Models\Register;
use App\Models\StockLevel;
use App\Support\Settings\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->setUpCompany();
    $this->register = Register::query()->firstOrFail();
    $this->cashier = $this->userWithRole('cashier', $this->store);
    $this->manager = $this->userWithRole('store_manager', $this->store, '4321');
    $this->shifts = app(ShiftService::class);
    $this->shift = $this->shifts->open($this->cashier, $this->register, ['1000' => 5]);
    $this->cash = PaymentMethod::query()->where('code', 'cash')->firstOrFail();
    $this->card = PaymentMethod::query()->where('code', 'card')->firstOrFail();
    $this->soap = $this->makeProduct('Lux Soap', '100');
    $this->receive($this->soap, '20', '70');
    $this->sell = fn (string $qty, array $payments, array $extra = []) => app(CheckoutService::class)->checkout($this->cashier, array_merge([
        'idempotency_key' => (string) Str::uuid(),
        'register_id' => $this->register->id,
        'lines' => [['variant_id' => $this->soap->id, 'variant_unit_id' => $this->soap->units->first()->id, 'quantity' => $qty]],
        'payments' => $payments,
    ], $extra));
});

it('computes expected cash from float, cash sales, payouts and drops but never card money', function () {
    ($this->sell)('3', [['payment_method_id' => $this->cash->id, 'amount' => '300']]);
    ($this->sell)('2', [['payment_method_id' => $this->card->id, 'amount' => '200', 'reference' => 'TID-55']]);
    $this->shifts->recordCashMovement($this->cashier, $this->shift, ['type' => 'payout', 'amount' => '150', 'reason_code_id' => ReasonCode::query()->where('code', 'staff_meals')->value('id')]);
    $this->shifts->recordCashMovement($this->cashier, $this->shift, ['type' => 'drop', 'amount' => '2000']);

    $x = $this->shifts->xReport($this->cashier, $this->shift);
    expect($x['cash']['expected'])->toBe('3150.00') // 5000 + 300 - 150 - 2000
        ->and($x['sales']['total'])->toBe('500.00')
        ->and(collect($x['tenders'])->firstWhere('method', 'Debit / credit card')['sales'])->toBe('200.00');
    expect($this->accountBalance('staff_meals'))->toBe('150.00');
});

it('needs approval for large payouts', function () {
    Settings::set('pos.payout_approval_amount', '100', 'store', $this->store->id);
    $payout = fn ($token = null) => $this->shifts->recordCashMovement($this->cashier, $this->shift, [
        'type' => 'payout', 'amount' => '400', 'reason_code_id' => ReasonCode::query()->where('code', 'transport')->value('id'), 'approval_token' => $token,
    ]);

    expect(fn () => DB::transaction(fn () => $payout()))->toThrow(BusinessRuleException::class, 'manager approval');
    $grant = app(ApprovalService::class)->grant($this->cashier, $this->manager, '4321', 'payout', $this->store->id);
    expect($payout($grant['token'])->approved_by)->toBe($this->manager->id);
});

it('closes with a denomination count, freezes the Z-Report and posts the shortage', function () {
    ($this->sell)('3', [['payment_method_id' => $this->cash->id, 'amount' => '300']]);
    // Expected 5300; cashier counts 5250 (Rs 50 short).
    $closed = $this->shifts->close($this->cashier, $this->shift, ['1000' => 5, '100' => 2, '50' => 1]);

    expect($closed->status)->toBe('closed')
        ->and($closed->expected_cash)->toBe('5300.00')
        ->and($closed->counted_cash)->toBe('5250.00')
        ->and($closed->variance)->toBe('-50.00')
        ->and($closed->variance_status)->toBe('pending_review')
        ->and($closed->z_number)->toBe(1)
        ->and($closed->z_snapshot['closing']['variance'])->toBe('-50.00');
    expect($this->accountBalance('cash_over_short'))->toBe('50.00');

    // Closing twice is refused and nothing is posted again.
    expect(fn () => DB::transaction(fn () => $this->shifts->close($this->cashier, $closed, ['1000' => 5])))->toThrow(BusinessRuleException::class, 'already closed');

    // The cashier cannot approve their own variance; the manager can.
    expect(fn () => $this->shifts->reviewVariance($this->cashier, $closed, 'ok'))->toThrow(BusinessRuleException::class);
    expect($this->shifts->reviewVariance($this->manager, $closed, 'Counted twice, short confirmed')->variance_status)->toBe('approved');
    $this->assertLedgerBalanced();
});

it('keeps the Z-Report identical to the X-Report figures at close', function () {
    ($this->sell)('2', [['payment_method_id' => $this->cash->id, 'amount' => '200']]);
    $x = $this->shifts->xReport($this->cashier, $this->shift);
    $closed = $this->shifts->close($this->cashier, $this->shift, ['1000' => 5, '100' => 2]);

    expect($closed->z_snapshot['sales'])->toBe($x['sales'])
        ->and($closed->z_snapshot['cash']['expected'])->toBe($x['cash']['expected'])
        ->and($closed->variance)->toBe('0.00');
});

it('refunds a returned item in cash, restocks it at original cost and reverses revenue', function () {
    $invoice = ($this->sell)('4', [['payment_method_id' => $this->cash->id, 'amount' => '400']]);
    $line = $invoice->lines->first();

    $note = app(CreditNoteService::class)->create($this->cashier, $invoice, [
        'lines' => [['invoice_line_id' => $line->id, 'quantity' => '1', 'disposition' => 'restock']],
        'reason_code_id' => ReasonCode::query()->where('type', 'return')->where('code', 'changed_mind')->value('id'),
        'settlement' => 'refund',
        'payment_method_id' => $this->cash->id,
        'shift_id' => $this->shift->id,
    ]);

    expect($note->total)->toBe('100.00')->and($note->cost_total)->toBe('70.00');
    expect(StockLevel::query()->where('variant_id', $this->soap->id)->sum('on_hand'))->toEqual('17.000');
    expect($this->accountBalance('sales_returns'))->toBe('100.00')
        ->and($this->accountBalance('cogs'))->toBe('210.00');
    expect($this->shifts->xReport($this->cashier, $this->shift)['cash']['expected'])->toBe('5300.00');
    $this->assertLedgerBalanced();

    // Cannot return more than was sold.
    expect(fn () => DB::transaction(fn () => app(CreditNoteService::class)->create($this->cashier, $invoice->fresh(), [
        'lines' => [['invoice_line_id' => $line->id, 'quantity' => '4', 'disposition' => 'restock']],
        'reason_code_id' => ReasonCode::query()->where('type', 'return')->value('id'),
        'settlement' => 'refund', 'shift_id' => $this->shift->id,
    ])))->toThrow(BusinessRuleException::class, 'can still be returned');
});

it('credits a Khata customer and puts damaged returns in the damaged area', function () {
    $customer = Contact::create(['type' => 'customer', 'name' => 'Sana']);
    CustomerProfile::create(['contact_id' => $customer->id, 'credit_limit' => '10000']);
    $invoice = ($this->sell)('5', [], ['customer_id' => $customer->id]);
    expect($this->accountBalance('accounts_receivable', $customer->id))->toBe('500.00');

    app(CreditNoteService::class)->create($this->cashier, $invoice, [
        'lines' => [['invoice_line_id' => $invoice->lines->first()->id, 'quantity' => '2', 'disposition' => 'damaged']],
        'reason_code_id' => ReasonCode::query()->where('type', 'return')->where('code', 'damaged_item')->value('id'),
        'settlement' => 'khata_credit',
    ]);

    expect($this->accountBalance('accounts_receivable', $customer->id))->toBe('300.00');
    $damaged = Location::query()->where('type', 'damaged')->value('id');
    expect(StockLevel::query()->where('location_id', $damaged)->sum('on_hand'))->toEqual('2.000');
    expect($invoice->fresh()->balanceDue())->toBe('300.00');
});

it('lets only one till resume a held cart', function () {
    $held = app(HeldCartService::class)->hold($this->cashier, $this->register, ['lines' => [['variant_id' => $this->soap->id, 'quantity' => '1']]], 'Uncle', null, '100');
    $other = $this->userWithRole('cashier', $this->store);

    app(HeldCartService::class)->resume($this->cashier, $held->id, $this->store->id);
    expect(fn () => DB::transaction(fn () => app(HeldCartService::class)->resume($other, $held->id, $this->store->id)))
        ->toThrow(BusinessRuleException::class, 'already resumed');
});
