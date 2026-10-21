<?php

use App\Domain\Reporting\ReportFilters;
use App\Domain\Setup\CompanyProvisioner;
use App\Models\Store;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->setUpCompany();
    $this->manager = $this->userWithRole('owner');
});

it('gives every new store Karachi time unless another zone is chosen', function () {
    $store = app(CompanyProvisioner::class)->createStore('Lahore', 'LHR');

    expect(Store::query()->find($store->id)->getAttributes()['timezone'])->toBe('Asia/Karachi');
});

it('reports a single store in that store\'s own time zone', function () {
    $this->store->update(['timezone' => 'Asia/Dubai']);

    $filters = ReportFilters::fromInput(['preset' => 'today', 'store_ids' => [$this->store->id]], $this->manager, 'reports.sales');

    expect($filters->timezone)->toBe('Asia/Dubai');
});

it('falls back to the company zone when several stores are reported together', function () {
    $other = app(CompanyProvisioner::class)->createStore('Lahore', 'LHR');
    $this->store->update(['timezone' => 'Asia/Dubai']);
    $other->update(['timezone' => 'Europe/London']);

    $filters = ReportFilters::fromInput(['preset' => 'today', 'store_ids' => [$this->store->id, $other->id]], $this->manager, 'reports.sales');

    expect($filters->timezone)->toBe($this->company->timezone);
});

it('places one instant in each store\'s own hour', function () {
    $dubai = $this->store;
    $dubai->update(['timezone' => 'Asia/Karachi']);
    $newYork = app(CompanyProvisioner::class)->createStore('New York', 'NYC');
    $newYork->update(['timezone' => 'America/New_York']);
    $filters = ReportFilters::fromInput(['preset' => 'today', 'store_ids' => [$dubai->id, $newYork->id]], $this->manager, 'reports.sales');

    $hourAt = fn (Store $store) => (int) DB::selectOne(
        'SELECT EXTRACT(HOUR FROM '.$filters->localTimeSql("TIMESTAMPTZ '2026-10-19 05:05:00+00'", (string) $store->id).') AS h'
    )->h;

    expect($hourAt($dubai))->toBe(10)   // 05:05 UTC is 10:05 in Karachi
        ->and($hourAt($newYork))->toBe(1); // and 01:05 in New York (daylight time)
});

it('refuses anything that is not a real time zone name before it reaches SQL', function () {
    expect(ReportFilters::safeTimezone("Asia/Karachi'; DROP TABLE stores; --"))->toBe('Asia/Karachi')
        ->and(ReportFilters::safeTimezone('Europe/London'))->toBe('Europe/London')
        ->and(ReportFilters::safeTimezone(null, 'UTC'))->toBe('UTC');
});

it('resolves custom dashboard dates and the comparison period before them', function () {
    $filters = ReportFilters::fromInput(['preset' => 'custom', 'from' => '2026-10-10', 'to' => '2026-10-16', 'compare' => 'previous_period'], $this->manager, 'dashboard.view');

    expect([$filters->from, $filters->to])->toBe(['2026-10-10', '2026-10-16'])
        ->and([$filters->compareFrom, $filters->compareTo])->toBe(['2026-10-03', '2026-10-09']);

    $none = ReportFilters::fromInput(['preset' => 'custom', 'from' => '2026-10-10', 'to' => '2026-10-16', 'compare' => 'none'], $this->manager, 'dashboard.view');
    expect($none->compareFrom)->toBeNull();
});
