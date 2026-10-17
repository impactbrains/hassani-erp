<?php

namespace App\Domain\Reporting;

use App\Models\User;
use App\Support\Settings\Settings;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Validated, normalised report filters. Periods are resolved to business
 * dates in the company time zone; the comparison period is the previous
 * period of the same length (or the same dates last year). Store filters are
 * always intersected with the stores the user may report on.
 */
final class ReportFilters
{
    public const PRESETS = ['today', 'yesterday', 'this_week', 'last_week', 'this_month', 'last_month', 'this_quarter', 'last_quarter', 'year_to_date', 'last_year', 'last_7_days', 'last_30_days', 'custom'];

    public const ENTITY_FILTERS = ['product_id', 'variant_id', 'category_id', 'brand_id', 'customer_id', 'cashier_id', 'agent_id', 'payment_method_id', 'supplier_id', 'location_id', 'rider_id', 'employee_id', 'account_id', 'reason_id', 'register_id', 'price_book_id'];

    public string $preset;

    public string $from;

    public string $to;

    public ?string $compareFrom = null;

    public ?string $compareTo = null;

    public string $compare;

    /** @var list<int> */
    public array $storeIds;

    /** @var array<string, int|null> */
    public array $entities = [];

    public ?string $groupBy;

    public ?string $search;

    public ?string $sort;

    public string $direction;

    public int $page;

    public int $perPage;

    /** @var array<string, string> */
    public array $options = [];

    public string $timezone;

    /** @param array<string, mixed> $input */
    public static function fromInput(array $input, User $user, string $permission, ?string $defaultPreset = null): self
    {
        $filters = new self;
        $company = CurrentCompany::get();
        $filters->timezone = $company?->timezone ?? 'Asia/Karachi';
        $today = CarbonImmutable::now($filters->timezone)->startOfDay();
        $filters->preset = in_array($input['preset'] ?? null, self::PRESETS, true) ? $input['preset'] : ($defaultPreset ?? (string) Settings::get('reports.default_period', null, $user->id));
        [$from, $to] = $filters->resolvePeriod($filters->preset, $today, $input['from'] ?? null, $input['to'] ?? null);
        $filters->from = $from->toDateString();
        $filters->to = $to->toDateString();

        $filters->compare = in_array($input['compare'] ?? 'previous_period', ['none', 'previous_period', 'previous_year'], true) ? ($input['compare'] ?? 'previous_period') : 'previous_period';
        if ($filters->compare === 'previous_period') {
            $days = $from->diffInDays($to) + 1;
            $filters->compareTo = $from->subDay()->toDateString();
            $filters->compareFrom = $from->subDays($days)->toDateString();
        } elseif ($filters->compare === 'previous_year') {
            $filters->compareFrom = $from->subYear()->toDateString();
            $filters->compareTo = $to->subYear()->toDateString();
        }

        $allowed = $user->hasPermission('reports.all_stores') ? $user->accessibleStoreIds() : $user->storeIdsFor($permission);
        if ($allowed === []) {
            $allowed = $user->accessibleStoreIds();
        }
        $requested = array_values(array_filter(array_map('intval', (array) ($input['store_ids'] ?? []))));
        $filters->storeIds = $requested === [] ? $allowed : array_values(array_intersect($requested, $allowed));
        if ($filters->storeIds === []) {
            $filters->storeIds = [0]; // nothing the user may see
        }

        foreach (self::ENTITY_FILTERS as $key) {
            $filters->entities[$key] = isset($input[$key]) && $input[$key] !== '' && $input[$key] !== null ? (int) $input[$key] : null;
        }
        $filters->groupBy = isset($input['group_by']) ? preg_replace('/[^a-z_]/', '', (string) $input['group_by']) : null;
        $filters->search = isset($input['search']) && trim((string) $input['search']) !== '' ? mb_substr(trim((string) $input['search']), 0, 100) : null;
        $filters->sort = isset($input['sort']) ? preg_replace('/[^a-z0-9_]/', '', (string) $input['sort']) : null;
        $filters->direction = ($input['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $filters->page = max(1, (int) ($input['page'] ?? 1));
        $filters->perPage = min(200, max(10, (int) ($input['per_page'] ?? 50)));
        foreach ((array) ($input['options'] ?? []) as $key => $value) {
            if (is_string($key) && is_scalar($value)) {
                $filters->options[preg_replace('/[^a-z_]/', '', $key)] = (string) $value;
            }
        }

        return $filters;
    }

    /** Neutral filters used only to read a report's column layout. */
    public static function fallback(): self
    {
        $filters = new self;
        $filters->timezone = 'Asia/Karachi';
        $filters->preset = 'today';
        $filters->from = $filters->to = CarbonImmutable::now()->toDateString();
        $filters->compare = 'none';
        $filters->storeIds = [0];
        $filters->groupBy = null;
        $filters->search = null;
        $filters->sort = null;
        $filters->direction = 'desc';
        $filters->page = 1;
        $filters->perPage = 50;
        foreach (self::ENTITY_FILTERS as $key) {
            $filters->entities[$key] = null;
        }

        return $filters;
    }

    public function entity(string $key): ?int
    {
        return $this->entities[$key] ?? null;
    }

    public function option(string $key, ?string $default = null): ?string
    {
        return $this->options[$key] ?? $default;
    }

    public function hasComparison(): bool
    {
        return $this->compareFrom !== null;
    }

    /** A copy of these filters over the comparison period. */
    public function comparison(): ?self
    {
        if (! $this->hasComparison()) {
            return null;
        }
        $copy = clone $this;
        $copy->from = $this->compareFrom;
        $copy->to = $this->compareTo;
        $copy->compareFrom = null;
        $copy->compareTo = null;

        return $copy;
    }

    public function days(): int
    {
        return (int) CarbonImmutable::parse($this->from)->diffInDays(CarbonImmutable::parse($this->to)) + 1;
    }

    /** @return array<string, mixed> for the UI, saved views and export metadata */
    public function toArray(): array
    {
        return array_filter([
            'preset' => $this->preset, 'from' => $this->from, 'to' => $this->to, 'compare' => $this->compare,
            'compare_from' => $this->compareFrom, 'compare_to' => $this->compareTo, 'store_ids' => $this->storeIds,
            'group_by' => $this->groupBy, 'search' => $this->search, 'sort' => $this->sort, 'direction' => $this->direction,
            'per_page' => $this->perPage, 'options' => $this->options,
        ] + $this->entities, fn ($v) => $v !== null && $v !== []);
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function resolvePeriod(string $preset, CarbonImmutable $today, ?string $from, ?string $to): array
    {
        $weekStart = match ((string) Settings::get('reporting.week_start')) {
            'saturday' => CarbonImmutable::SATURDAY,
            'sunday' => CarbonImmutable::SUNDAY,
            default => CarbonImmutable::MONDAY,
        };
        $fiscalStart = (int) Settings::get('fiscal.year_start_month');

        return match ($preset) {
            'today' => [$today, $today],
            'yesterday' => [$today->subDay(), $today->subDay()],
            'this_week' => [$today->startOfWeek($weekStart), $today],
            'last_week' => [$today->startOfWeek($weekStart)->subWeek(), $today->startOfWeek($weekStart)->subDay()],
            'this_month' => [$today->startOfMonth(), $today],
            'last_month' => [$today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()->startOfDay()],
            'this_quarter' => [$today->startOfQuarter(), $today],
            'last_quarter' => [$today->subQuarterNoOverflow()->startOfQuarter(), $today->subQuarterNoOverflow()->endOfQuarter()->startOfDay()],
            'year_to_date' => [$today->month >= $fiscalStart ? $today->setDate($today->year, $fiscalStart, 1) : $today->setDate($today->year - 1, $fiscalStart, 1), $today],
            'last_year' => [$today->subYear()->startOfYear(), $today->subYear()->endOfYear()->startOfDay()],
            'last_7_days' => [$today->subDays(6), $today],
            'last_30_days' => [$today->subDays(29), $today],
            'custom' => $this->customPeriod($from, $to),
            default => [$today->startOfMonth(), $today],
        };
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function customPeriod(?string $from, ?string $to): array
    {
        try {
            $start = CarbonImmutable::parse((string) $from)->startOfDay();
            $end = CarbonImmutable::parse((string) $to)->startOfDay();
        } catch (\Throwable) {
            throw ValidationException::withMessages(['from' => 'Choose a valid start and end date.']);
        }
        if ($end->lt($start)) {
            throw ValidationException::withMessages(['to' => 'The end date is before the start date.']);
        }
        if ($start->diffInDays($end) > 3660) {
            throw ValidationException::withMessages(['from' => 'Choose a period of ten years or less.']);
        }

        return [$start, $end];
    }
}
