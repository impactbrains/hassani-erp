<?php

namespace App\Domain\Accounting;

use App\Exceptions\BusinessRuleException;
use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use App\Support\Settings\Settings;
use Carbon\CarbonImmutable;

/**
 * Fiscal years and monthly periods. Years are created on demand from the
 * company's fiscal-year start month (July for Pakistan by default). Posting
 * into a closed period is refused.
 */
final class FiscalCalendar
{
    public function assertOpen(string $date): FiscalPeriod
    {
        $period = $this->periodFor($date);
        if ($period->status !== 'open') {
            throw new BusinessRuleException(
                "The accounting period {$period->name} is closed. Post the correction in an open period or ask an accountant to reopen it.",
                'period_closed',
                ['period' => $period->name],
            );
        }

        return $period;
    }

    public function periodFor(string $date): FiscalPeriod
    {
        $period = FiscalPeriod::query()->where('starts_on', '<=', $date)->where('ends_on', '>=', $date)->first();
        if ($period) {
            return $period;
        }
        $this->createYearContaining($date);

        return FiscalPeriod::query()->where('starts_on', '<=', $date)->where('ends_on', '>=', $date)->firstOrFail();
    }

    public function createYearContaining(string $date): FiscalYear
    {
        $startMonth = (int) Settings::get('fiscal.year_start_month');
        $day = CarbonImmutable::parse($date);
        $startYear = $day->month >= $startMonth ? $day->year : $day->year - 1;
        $start = CarbonImmutable::create($startYear, $startMonth, 1);
        $end = $start->addYear()->subDay();
        $name = $startMonth === 1 ? (string) $startYear : 'FY '.$startYear.'-'.substr((string) ($startYear + 1), -2);

        $year = FiscalYear::query()->firstOrCreate(
            ['starts_on' => $start->toDateString()],
            ['name' => $name, 'ends_on' => $end->toDateString(), 'status' => 'open'],
        );
        for ($month = $start; $month->lte($end); $month = $month->addMonth()) {
            FiscalPeriod::query()->firstOrCreate(
                ['starts_on' => $month->toDateString()],
                ['fiscal_year_id' => $year->id, 'name' => $month->format('M Y'), 'ends_on' => $month->endOfMonth()->toDateString(), 'status' => 'open'],
            );
        }

        return $year;
    }
}
