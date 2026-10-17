<?php

use App\Domain\Pos\CheckoutService;
use App\Domain\Pos\ShiftService;
use App\Domain\Reporting\CsvWriter;
use App\Domain\Reporting\ForecastService;
use App\Domain\Reporting\ReportExporter;
use App\Domain\Reporting\ReportFilters;
use App\Domain\Reporting\ReportRegistry;
use App\Domain\Reporting\SalesMetrics;
use App\Domain\Sales\CreditNoteService;
use App\Models\Contact;
use App\Models\CustomerProfile;
use App\Models\PaymentMethod;
use App\Models\ReasonCode;
use App\Models\Register;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->setUpCompany();
    $this->owner = $this->userWithRole('owner');
    $this->cashier = $this->userWithRole('cashier', $this->store);
    $register = Register::query()->firstOrFail();
    $shift = app(ShiftService::class)->open($this->cashier, $register, ['1000' => 1]);
    $cash = PaymentMethod::query()->where('code', 'cash')->firstOrFail();
    $soap = $this->makeProduct('Lux Soap', '120');
    $tea = $this->makeProduct('=Tapal Danedar', '500');
    $floor = $this->store->defaultSellingLocation()->id;
    app(\App\Domain\Inventory\AdjustmentService::class)->create($this->owner, $this->store, 'opening', [
        ['variant_id' => $soap->id, 'location_id' => $floor, 'quantity' => '50', 'unit_cost' => '90'],
        ['variant_id' => $tea->id, 'location_id' => $floor, 'quantity' => '20', 'unit_cost' => '400'],
    ], null, 'Opening stock');
    $customer = Contact::create(['type' => 'customer', 'name' => 'Kiryana Store Gulshan']);
    CustomerProfile::create(['contact_id' => $customer->id, 'credit_limit' => '10000', 'category' => 'wholesale']);
    $sell = fn (array $lines, array $payments, ?int $customerId = null) => app(CheckoutService::class)->checkout($this->cashier, [
        'idempotency_key' => (string) Str::uuid(), 'register_id' => $register->id, 'customer_id' => $customerId,
        'lines' => array_map(fn ($l) => ['variant_id' => $l[0]->id, 'variant_unit_id' => $l[0]->units->first()->id, 'quantity' => $l[1]], $lines),
        'payments' => $payments,
    ]);
    $first = $sell([[$soap, '3'], [$tea, '1']], [['payment_method_id' => $cash->id, 'amount' => '860', 'tendered' => '1000']]);
    $sell([[$tea, '2']], [], $customer->id);
    app(CreditNoteService::class)->create($this->cashier, $first, [
        'lines' => [['invoice_line_id' => $first->lines->first()->id, 'quantity' => '1', 'disposition' => 'restock']],
        'reason_code_id' => ReasonCode::query()->where('type', 'return')->value('id'), 'settlement' => 'refund', 'payment_method_id' => $cash->id, 'shift_id' => $shift->id,
    ]);
    app(ShiftService::class)->close($this->cashier, $shift->fresh(), ['1000' => 1, '500' => 1, '100' => 3, '20' => 1]);
    app(ForecastService::class)->run($this->store, $this->owner->id);
    $this->filters = fn (array $input = [], string $permission = 'reports.sales') => ReportFilters::fromInput($input + ['preset' => 'last_30_days', 'compare' => 'previous_period'], $this->owner, $permission);
});

it('runs every report: rows, summary, chart and insights', function () {
    $reports = ReportRegistry::all();
    expect(count($reports))->toBeGreaterThanOrEqual(45);

    foreach ($reports as $key => $report) {
        $filters = ($this->filters)(['group_by' => array_key_first($report->groupByOptions())], $report->permission());
        try {
            $page = $report->sortedRows($filters)->paginate(50);
            $summary = $report->summary($filters);
            $report->chart($filters);
            $report->insights($filters, $summary);
        } catch (Throwable $e) {
            throw new RuntimeException("Report {$key} failed: ".$e->getMessage(), 0, $e);
        }
        $columns = array_keys($report->columns($filters));
        if ($page->count() > 0) {
            expect(array_keys((array) $page->items()[0]))->toEqualCanonicalizing($columns, "Report {$key} returns columns that do not match its definition");
        }
        expect($report->definition())->toHaveKeys(['source', 'date_basis']);
    }
});

it('agrees with the headline metrics and with the ledger', function () {
    $f = ($this->filters)();
    $totals = SalesMetrics::totals($f);
    expect($totals['transactions'])->toBe(2)
        ->and($totals['gross_sales'])->toBe('1860.00')
        ->and($totals['returns'])->toBe('120.00')
        ->and($totals['net_sales'])->toBe('1740.00')
        ->and($totals['khata_extended'])->toBe('1000.00');

    $summary = ReportRegistry::find('sales.summary')->sortedRows($f)->get();
    expect((string) $summary->sum('net_sales'))->toEqual('1740');

    $valuation = collect(ReportRegistry::find('inventory.valuation')->summary(($this->filters)([], 'inventory.valuation.view')))->keyBy('key');
    expect($valuation['difference']['value'])->toBe('0.00');

    $tb = collect(ReportRegistry::find('finance.trial_balance')->summary(($this->filters)([], 'reports.finance')))->keyBy('key');
    expect($tb['debits']['value'])->toBe($tb['credits']['value']);

    $bs = collect(ReportRegistry::find('finance.balance_sheet')->summary(($this->filters)([], 'reports.finance')))->keyBy('key');
    expect($bs['equity']['note'])->toBe('Balances');

    $khata = collect(ReportRegistry::find('customers.khata')->summary(($this->filters)([], 'reports.customers')))->keyBy('key');
    expect($khata['total']['value'])->toBe('1000.00');
});

it('hides cost columns from users without the cost permission', function () {
    $report = ReportRegistry::find('products.sales');
    $manager = $this->userWithRole('cashier', $this->store);
    $visible = $report->visibleColumns(ReportFilters::fallback(), $manager);
    expect($visible)->not->toHaveKey('cost')->not->toHaveKey('margin')->toHaveKey('revenue');
    expect($report->visibleColumns(ReportFilters::fallback(), $this->owner))->toHaveKey('margin');
});

it('limits store filters to the stores the user may report on', function () {
    $other = app(\App\Domain\Setup\CompanyProvisioner::class)->createStore('Branch 2', 'B2');
    $manager = $this->userWithRole('store_manager', $this->store);
    $f = ReportFilters::fromInput(['store_ids' => [$other->id]], $manager, 'reports.sales');
    expect($f->storeIds)->toBe([0]);
    $f = ReportFilters::fromInput([], $manager, 'reports.sales');
    expect($f->storeIds)->toBe([$this->store->id]);
});

it('exports the full filtered dataset as safe UTF-8 CSV and audits it', function () {
    $report = ReportRegistry::find('products.sales');
    $export = app(ReportExporter::class)->request($this->owner, $report, ($this->filters)([], 'reports.products'));

    expect($export->status)->toBe('completed')->and($export->row_count)->toBe(2);
    $csv = Storage::disk('local')->get($export->file_path);
    expect(str_starts_with($csv, "\xEF\xBB\xBF"))->toBeTrue()
        ->and($csv)->toContain("'=Tapal Danedar")
        ->and(DB::table('audit_logs')->where('event', 'report.exported')->count())->toBe(1);

    $cashier = $this->cashier;
    expect(fn () => app(ReportExporter::class)->download($cashier, $export))->toThrow(\App\Exceptions\BusinessRuleException::class);
    expect(CsvWriter::safeText('-1+2'))->toBe("'-1+2");
});
