<?php

namespace App\Domain\Reporting;

use App\Models\DemandForecast;
use App\Models\ForecastRun;
use App\Models\ForecastSeries;
use App\Models\Store;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Weekly SKU demand forecasts with honest validation.
 *
 * History: units sold per week (sales + bundle consumption - returns), up to
 * 104 weeks. Weeks that ended out of stock are excluded, because low sales
 * while the shelf was empty are not low demand.
 * Candidate methods: last week (naive), 4-week moving average, trend-adjusted
 * 8-week average and, with over a year of history, same week last year
 * (seasonal naive). Each is backtested one step ahead over the last 8 weeks;
 * the lowest WAPE wins. An 80% interval comes from the backtest errors.
 * Quality: fewer than 8 weeks -> insufficient (no forecast); WAPE above 50%
 * or fewer than 16 weeks -> experimental; otherwise validated.
 * Nothing is ordered from these numbers automatically.
 */
final class ForecastService
{
    public const VERSION = 'wk-1.0';

    private const BACKTEST = 8;

    private const HORIZON = 8;

    public function run(Store $store, ?int $userId = null): ForecastRun
    {
        $end = CarbonImmutable::parse($store->businessDate())->startOfWeek()->subDay(); // last complete week (Mon-Sun)
        $start = $end->subWeeks(104)->addDay();
        $weekly = DB::table('stock_movements')
            ->where('store_id', $store->id)
            ->whereIn('movement_type', ['sale', 'bundle_consume', 'sale_return', 'bundle_return'])
            ->whereBetween('business_date', [$start->toDateString(), $end->toDateString()])
            ->groupBy('variant_id', DB::raw("date_trunc('week', business_date)"))
            ->selectRaw("variant_id, to_char(date_trunc('week', business_date), 'YYYY-MM-DD') wk, -SUM(quantity) units")
            ->get()->groupBy('variant_id');
        $stockouts = $this->stockoutWeeks($store->id, $start, $end);

        return DB::transaction(function () use ($store, $userId, $start, $end, $weekly, $stockouts) {
            $run = ForecastRun::create([
                'store_id' => $store->id, 'level' => 'variant', 'granularity' => 'week', 'horizon' => self::HORIZON,
                'history_start' => $start->toDateString(), 'history_end' => $end->toDateString(), 'backtest_periods' => self::BACKTEST,
                'model_version' => self::VERSION, 'created_by' => $userId,
            ]);
            $count = 0;
            foreach ($weekly as $variantId => $rows) {
                $byWeek = $rows->pluck('units', 'wk')->all();
                $first = min(array_keys($byWeek));
                $series = [];
                $excluded = 0;
                for ($w = CarbonImmutable::parse($first); $w->lte($end); $w = $w->addWeek()) {
                    $key = $w->toDateString();
                    if (isset($stockouts[$variantId][$key])) {
                        $excluded++;
                        continue;
                    }
                    $series[$key] = max(0.0, (float) ($byWeek[$key] ?? 0));
                }
                $this->forecastSeries($run, (int) $variantId, $series, $excluded, $end);
                $count++;
            }
            $run->update(['series_count' => $count]);

            return $run;
        });
    }

    /** @param array<string, float> $history week start => units */
    private function forecastSeries(ForecastRun $run, int $variantId, array $history, int $excluded, CarbonImmutable $end): void
    {
        $values = array_values($history);
        $n = count($values);
        $warnings = [];
        if ($excluded > 0) {
            $warnings[] = "{$excluded} out-of-stock weeks were left out of the history.";
        }
        if ($n < self::BACKTEST) {
            ForecastSeries::create(['forecast_run_id' => $run->id, 'store_id' => $run->store_id, 'level' => 'variant', 'level_id' => $variantId, 'method' => 'none',
                'history_periods' => $n, 'excluded_stockout_periods' => $excluded, 'quality' => 'insufficient_history',
                'warnings' => array_merge($warnings, ["Only {$n} weeks of sales; at least ".self::BACKTEST.' are needed for a forecast.'])]);

            return;
        }

        $methods = ['naive' => fn (array $h) => end($h), 'moving_average_4' => fn (array $h) => array_sum(array_slice($h, -4)) / min(4, count($h)), 'trend_average_8' => fn (array $h) => $this->trendAverage($h)];
        if ($n >= 60) {
            $methods['seasonal_naive'] = fn (array $h) => $h[count($h) - 52] ?? end($h);
        }
        $scores = [];
        foreach ($methods as $name => $method) {
            $errors = [];
            $actual = 0.0;
            for ($i = $n - self::BACKTEST; $i < $n; $i++) {
                $prediction = max(0.0, (float) $method(array_slice($values, 0, $i)));
                $errors[] = $values[$i] - $prediction;
                $actual += $values[$i];
            }
            $absolute = array_sum(array_map('abs', $errors));
            $scores[$name] = ['mae' => $absolute / count($errors), 'wape' => $actual > 0 ? $absolute / $actual : null, 'bias' => array_sum($errors) / count($errors), 'rmse' => sqrt(array_sum(array_map(fn ($e) => $e * $e, $errors)) / count($errors))];
        }
        uasort($scores, fn ($a, $b) => ($a['wape'] ?? $a['mae']) <=> ($b['wape'] ?? $b['mae']));
        $best = array_key_first($scores);
        $score = $scores[$best];
        $quality = ($n < 16 || ($score['wape'] !== null && $score['wape'] > 0.5)) ? 'experimental' : 'validated';
        if ($score['wape'] === null) {
            $warnings[] = 'No sales in the backtest weeks, so the error is measured in units only.';
        }
        $recent = array_sum(array_slice($values, -4)) / 4;
        $earlier = $n >= 8 ? array_sum(array_slice($values, -8, 4)) / 4 : $recent;

        $series = ForecastSeries::create([
            'forecast_run_id' => $run->id, 'store_id' => $run->store_id, 'level' => 'variant', 'level_id' => $variantId, 'method' => $best,
            'history_periods' => $n, 'excluded_stockout_periods' => $excluded, 'mae' => round($score['mae'], 3),
            'wape' => $score['wape'] === null ? null : round($score['wape'], 4), 'bias' => round($score['bias'], 3), 'quality' => $quality,
            'method_scores' => array_map(fn ($s) => array_map(fn ($v) => $v === null ? null : round($v, 4), $s), $scores),
            'drivers' => ['recent_4_week_average' => round($recent, 2), 'previous_4_week_average' => round($earlier, 2), 'trend_percent' => $earlier > 0 ? round(100 * ($recent - $earlier) / $earlier, 1) : null],
            'warnings' => $warnings,
        ]);
        $method = $methods[$best];
        $path = $values;
        for ($step = 1; $step <= self::HORIZON; $step++) {
            $prediction = max(0.0, (float) $method($path));
            $spread = 1.2816 * $score['rmse'] * sqrt($step);
            DemandForecast::create([
                'forecast_series_id' => $series->id, 'period_start' => $end->addDay()->addWeeks($step - 1)->toDateString(), 'horizon_step' => $step,
                'predicted' => round($prediction, 3), 'lower' => round(max(0, $prediction - $spread), 3), 'upper' => round($prediction + $spread, 3),
            ]);
            $path[] = $prediction;
        }
    }

    /** @param list<float> $h */
    private function trendAverage(array $h): float
    {
        $window = array_slice($h, -8);
        $count = count($window);
        if ($count < 2) {
            return (float) end($h);
        }
        $xMean = ($count - 1) / 2;
        $yMean = array_sum($window) / $count;
        $num = 0.0;
        $den = 0.0;
        foreach ($window as $x => $y) {
            $num += ($x - $xMean) * ($y - $yMean);
            $den += ($x - $xMean) ** 2;
        }
        $slope = $den > 0 ? $num / $den : 0;

        return $yMean + $slope * ($count - $xMean);
    }

    /** @return array<int, array<string, true>> variant => weeks that ended with no stock in the store */
    private function stockoutWeeks(int $storeId, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $rows = DB::table('stock_movements')->where('store_id', $storeId)->where('business_date', '<=', $end->toDateString())
            ->groupBy('variant_id', DB::raw("date_trunc('week', business_date)"))
            ->orderBy('variant_id')->orderBy(DB::raw("date_trunc('week', business_date)"))
            ->selectRaw("variant_id, to_char(date_trunc('week', business_date), 'YYYY-MM-DD') wk, SUM(quantity) q")->get()->groupBy('variant_id');
        $result = [];
        foreach ($rows as $variantId => $weeks) {
            $balance = 0.0;
            $byWeek = $weeks->pluck('q', 'wk')->all();
            $first = min(array_keys($byWeek));
            for ($w = CarbonImmutable::parse($first); $w->lte($end); $w = $w->addWeek()) {
                $balance += (float) ($byWeek[$w->toDateString()] ?? 0);
                if ($balance <= 0 && $w->gte($start)) {
                    $result[$variantId][$w->toDateString()] = true;
                }
            }
        }

        return $result;
    }
}
