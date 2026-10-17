<?php

use App\Domain\Accounting\JournalPoster;
use App\Domain\Inventory\CostingService;
use App\Exceptions\BusinessRuleException;
use App\Models\JournalEntry;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\VariantCost;
use App\Support\Settings\Settings;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(fn () => $this->setUpCompany());

it('refuses to take stock below zero', function () {
    $variant = $this->makeProduct();
    $this->receive($variant, '5', '80');

    expect(fn () => DB::transaction(fn () => $this->receive($variant, '-6', '0')))->toThrow(BusinessRuleException::class, 'Not enough stock');
    expect(StockLevel::query()->where('variant_id', $variant->id)->sum('on_hand'))->toEqual('5.000');
});

it('keeps stock movements append-only at the database level', function () {
    $variant = $this->makeProduct();
    $this->receive($variant, '5', '80');
    $movement = StockMovement::query()->firstOrFail();

    expect(fn () => DB::transaction(fn () => DB::table('stock_movements')->where('id', $movement->id)->update(['quantity' => 50])))
        ->toThrow(QueryException::class, 'append-only');
    expect(fn () => DB::transaction(fn () => DB::table('stock_movements')->where('id', $movement->id)->delete()))
        ->toThrow(QueryException::class, 'append-only');
});

it('tracks FIFO and weighted average cost together and posts the configured one', function () {
    $variant = $this->makeProduct();
    $this->receive($variant, '10', '100');
    $this->receive($variant, '10', '130');

    $costing = app(CostingService::class);
    $average = VariantCost::query()->where('variant_id', $variant->id)->firstOrFail();
    expect($average->average_cost)->toBe('115.000000');

    // Weighted average (default): 12 units at 115
    $cost = DB::transaction(fn () => $costing->consume($this->store->id, $variant->id, '12'));
    expect($cost['total_cost'])->toBe('1380.00')
        ->and($cost['fifo_total'])->toBe('1260.00'); // 10 x 100 + 2 x 130
});

it('uses FIFO cost when the company chooses FIFO before any stock moves', function () {
    Settings::set('inventory.valuation_method', 'fifo');
    $variant = $this->makeProduct();
    $this->receive($variant, '10', '100');
    $this->receive($variant, '10', '130');

    $cost = DB::transaction(fn () => app(CostingService::class)->consume($this->store->id, $variant->id, '12'));
    expect($cost['total_cost'])->toBe('1260.00');
});

it('locks the valuation method once stock has moved', function () {
    $variant = $this->makeProduct();
    $this->receive($variant, '1', '10');

    expect(fn () => Settings::set('inventory.valuation_method', 'fifo'))->toThrow(BusinessRuleException::class, 'locked');
});

it('posts balanced journals once per source and purpose and protects posted entries', function () {
    $poster = app(JournalPoster::class);
    $variant = $this->makeProduct();
    $entry = $poster->post('test', $variant, now()->toDateString(), 'Owner capital', [
        ['account' => 'cash_on_hand', 'debit' => '1000'],
        ['account' => 'owner_capital', 'credit' => '1000'],
    ]);
    $again = $poster->post('test', $variant, now()->toDateString(), 'Owner capital', [
        ['account' => 'cash_on_hand', 'debit' => '1000'],
        ['account' => 'owner_capital', 'credit' => '1000'],
    ]);

    expect($again->id)->toBe($entry->id)
        ->and(JournalEntry::query()->count())->toBe(1);
    expect(fn () => DB::transaction(fn () => DB::table('journal_entries')->where('id', $entry->id)->update(['description' => 'changed'])))
        ->toThrow(QueryException::class, 'cannot be changed');
    expect(fn () => DB::transaction(fn () => DB::table('journal_lines')->where('journal_entry_id', $entry->id)->update(['debit' => 5])))
        ->toThrow(QueryException::class);

    $poster->reverse($entry, now()->toDateString(), 'test');
    expect($this->accountBalance('cash_on_hand'))->toBe('0.00');
    $this->assertLedgerBalanced();
});

it('rejects an unbalanced journal at the database level', function () {
    $entryId = DB::table('journal_entries')->insertGetId([
        'company_id' => $this->company->id, 'number' => 'X-1', 'entry_date' => now()->toDateString(), 'status' => 'draft',
        'description' => 'bad', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('journal_lines')->insert([
        'company_id' => $this->company->id, 'journal_entry_id' => $entryId, 'account_id' => \App\Domain\Accounting\Accounts::id('cash_on_hand'),
        'debit' => 100, 'credit' => 0, 'entry_date' => now()->toDateString(),
    ]);

    expect(fn () => DB::transaction(fn () => DB::table('journal_entries')->where('id', $entryId)->update(['status' => 'posted'])))
        ->toThrow(QueryException::class, 'unbalanced');
});
