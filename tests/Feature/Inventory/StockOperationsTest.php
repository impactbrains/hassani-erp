<?php

use App\Domain\Inventory\AdjustmentService;
use App\Domain\Inventory\CountService;
use App\Domain\Inventory\RepackService;
use App\Domain\Inventory\TransferService;
use App\Domain\Setup\CompanyProvisioner;
use App\Exceptions\BusinessRuleException;
use App\Models\CostLayer;
use App\Models\Location;
use App\Models\ReasonCode;
use App\Models\StockLevel;
use App\Models\Unit;
use App\Models\VariantCost;
use App\Support\Decimal;
use App\Support\Settings\Settings;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->setUpCompany();
    $this->owner = $this->userWithRole('owner');
    $this->clerk = $this->userWithRole('warehouse_worker', $this->store);
    $this->keeper = $this->userWithRole('inventory_manager', $this->store);
    $this->floor = $this->store->defaultSellingLocation();
    $this->rice = $this->makeProduct('Basmati Rice 5kg', '1500');
    $this->inventoryValue = function () {
        $value = '0';
        foreach (VariantCost::query()->get() as $cost) {
            $value = Decimal::add($value, Decimal::mul($cost->quantity, $cost->average_cost));
        }

        return Decimal::money($value);
    };
});

it('posts opening stock with a cost and books it to opening equity', function () {
    $adjustment = app(AdjustmentService::class)->create($this->owner, $this->store, 'opening', [
        ['variant_id' => $this->rice->id, 'location_id' => $this->floor->id, 'quantity' => '40', 'unit_cost' => '1200'],
    ], null, 'Opening count');

    expect($adjustment->status)->toBe('posted');
    expect($this->accountBalance('inventory'))->toBe('48000.00')
        ->and($this->accountBalance('opening_balance_equity'))->toBe('-48000.00');
    expect($this->accountBalance('inventory'))->toBe(($this->inventoryValue)());
});

it('routes write-offs through approval and needs two approvers above the dual limit', function () {
    $this->receive($this->rice, '40', '1200');
    Settings::set('inventory.dual_approval_value', '5000');
    $damaged = ReasonCode::query()->where('type', 'write_off')->where('code', 'damaged')->value('id');
    $manager = $this->userWithRole('store_manager', $this->store);

    $writeOff = app(AdjustmentService::class)->create($this->keeper, $this->store, 'write_off', [
        ['variant_id' => $this->rice->id, 'location_id' => $this->floor->id, 'quantity' => '5'],
    ], $damaged, 'Water damage');
    expect($writeOff->status)->toBe('pending_approval');

    $once = app(AdjustmentService::class)->approve($manager, $writeOff);
    expect($once->status)->toBe('pending_approval');
    expect(fn () => DB::transaction(fn () => app(AdjustmentService::class)->approve($manager, $once)))->toThrow(BusinessRuleException::class, 'second, different approver');

    $posted = app(AdjustmentService::class)->approve($this->owner, $once);
    expect($posted->status)->toBe('posted');
    expect(StockLevel::query()->sum('on_hand'))->toEqual('35.000');
    expect($this->accountBalance('stock_write_offs'))->toBe('6000.00');
    $this->assertLedgerBalanced();
});

it('moves stock between stores through transit and writes off a shortfall with a reason', function () {
    app(AdjustmentService::class)->create($this->owner, $this->store, 'opening', [
        ['variant_id' => $this->rice->id, 'location_id' => $this->floor->id, 'quantity' => '20', 'unit_cost' => '1200'],
    ], null, null);
    $second = app(CompanyProvisioner::class)->createStore('Branch 2', 'B2');
    $to = $second->defaultReceivingLocation();
    $short = ReasonCode::query()->where('type', 'transfer_discrepancy')->where('code', 'short_shipped')->value('id');
    $service = app(TransferService::class);

    $transfer = $service->create($this->owner, $this->floor->id, $to->id, [['variant_id' => $this->rice->id, 'quantity' => '10']], null);
    $transfer = $service->approve($this->owner, $transfer);
    $transfer = $service->dispatch($this->owner, $transfer);
    $transit = Location::query()->where('store_id', $second->id)->where('type', 'transit')->first();
    expect(StockLevel::query()->where('location_id', $transit->id)->sum('on_hand'))->toEqual('10.000');

    $line = $transfer->lines->first();
    $received = $service->receive($this->owner, $transfer, [$line->id => ['quantity' => '9', 'reason_id' => $short, 'note' => '1 bag missing']]);

    expect($received->status)->toBe('partially_received');
    expect(StockLevel::query()->where('location_id', $to->id)->sum('on_hand'))->toEqual('9.000')
        ->and(StockLevel::query()->where('location_id', $transit->id)->sum('on_hand'))->toEqual('0.000');
    expect(VariantCost::query()->where('store_id', $second->id)->value('quantity'))->toBe('9.000');
    expect($this->accountBalance('stock_write_offs'))->toBe('1200.00');
    expect($this->accountBalance('inventory'))->toBe(Decimal::money(Decimal::sub('24000', '1200')));
    $this->assertLedgerBalanced();
});

it('posts only true count variances when sales happen during the count', function () {
    $this->receive($this->rice, '30', '1000');
    $counts = app(CountService::class);
    $count = $counts->plan($this->keeper, $this->store, 'full', [], blind: true, secondCount: false, assignedTo: $this->clerk->id, note: null);
    $count = $counts->start($this->keeper, $count);
    $line = $count->lines()->first();
    expect($line->expected_quantity)->toBe('30.000');

    // 2 bags are sold while counting...
    $this->receive($this->rice, '-2', '0');
    // ...the counter finds 27 (one bag genuinely missing).
    $counts->record($this->clerk, $line, '27');
    $counts->submit($this->clerk, $count->fresh());

    $posted = $counts->approveAndPost($this->keeper, $count->fresh());
    $line = $posted->lines->first();
    expect($line->movement_since_snapshot)->toBe('-2.000')
        ->and($line->variance_quantity)->toBe('-1.000')
        ->and($line->variance_value)->toBe('-1000.00');
    expect(StockLevel::query()->sum('on_hand'))->toEqual('27.000');
    expect($this->accountBalance('stock_count_variance'))->toBe('1000.00');
});

it('requires a different person to recount big count differences', function () {
    $this->receive($this->rice, '10', '1000');
    $counts = app(CountService::class);
    $count = $counts->start($this->keeper, $counts->plan($this->keeper, $this->store, 'full', [], true, false, null, null));
    $line = $count->lines()->first();
    $counts->record($this->clerk, $line, '5');
    $counts->submit($this->clerk, $count->fresh());

    expect(fn () => DB::transaction(fn () => $counts->approveAndPost($this->keeper, $count->fresh())))->toThrow(BusinessRuleException::class, 'second count');
    expect(fn () => $counts->record($this->clerk, $line->fresh(), '6', isSecondCount: true))->toThrow(BusinessRuleException::class, 'someone else');

    $counts->record($this->keeper, $line->fresh(), '6', isSecondCount: true);
    $posted = $counts->approveAndPost($this->owner, $count->fresh());
    expect($posted->lines->first()->variance_quantity)->toBe('-4.000');
});

it('repacks a bulk bag into retail packs and carries the full cost', function () {
    $kg = Unit::query()->where('code', 'kg')->firstOrFail();
    $bulk = $this->makeProduct('Sugar 50kg bag', '7000');
    $pack = $this->makeProduct('Sugar 1kg pack', '160');
    $this->receive($bulk, '2', '6500');

    app(RepackService::class)->post($this->owner, [
        'source_variant_id' => $bulk->id, 'source_location_id' => $this->floor->id, 'source_quantity' => '1',
        'target_variant_id' => $pack->id, 'target_location_id' => $this->floor->id, 'target_quantity' => '49',
    ]);

    expect(VariantCost::query()->where('variant_id', $pack->id)->value('average_cost'))->toBe('132.653061');
    expect(($this->inventoryValue)())->toBe('13000.00');
});
