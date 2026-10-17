<?php

namespace App\Domain\Reporting;

use App\Models\User;
use App\Support\Permissions\PermissionRegistry;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * A report definition. The same definition feeds the screen (cards, chart,
 * table, drill-down) and the CSV export, so a figure means the same thing
 * everywhere. Detail rows always come from SQL with server-side sorting and
 * pagination; nothing loads the whole history into the browser.
 */
abstract class Report
{
    abstract public function key(): string;

    abstract public function title(): string;

    abstract public function section(): string;

    abstract public function permission(): string;

    abstract public function description(): string;

    /**
     * What the report measures and how: data source, date basis, metric
     * definitions, exclusions and known limitations (shown on the page).
     *
     * @return array{source: string, date_basis: string, definitions?: array<string, string>, exclusions?: list<string>, limitations?: list<string>}
     */
    abstract public function definition(): array;

    /** @return array<string, array{label: string, type: string, sensitive?: string, sortable?: bool}> */
    abstract public function columns(ReportFilters $filters): array;

    /** The complete (unpaginated) dataset; selected aliases match columns(). */
    abstract public function rows(ReportFilters $filters): Builder|EloquentBuilder;

    public function titleUr(): ?string
    {
        return null;
    }

    /** @return list<string> filter controls to show (period, stores, product, category, customer ...) */
    public function filters(): array
    {
        return ['period', 'stores'];
    }

    /** @return array<string, string> */
    public function groupByOptions(): array
    {
        return [];
    }

    /** @return list<array{key: string, label: string, value: string|int|null, type: string, previous?: string|int|null, note?: string|null}> */
    public function summary(ReportFilters $filters): array
    {
        return [];
    }

    /** @return array{type: string, labels: list<string>, series: list<array{name: string, data: list<float|int|string|null>}>}|null */
    public function chart(ReportFilters $filters): ?array
    {
        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $summary
     * @return list<array{kind: string, text: string, evidence?: string, action?: array{label: string, href: string}}>
     */
    public function insights(ReportFilters $filters, array $summary): array
    {
        return [];
    }

    /** @return array{0: string, 1: string} */
    public function defaultSort(): array
    {
        return [array_key_first($this->columns(ReportFilters::fallback())) ?? 'id', 'asc'];
    }

    /** @return array{column: string, route: string, param: string}|null */
    public function drillDown(): ?array
    {
        return null;
    }

    public function supportsComparison(): bool
    {
        return false;
    }

    public function isSensitive(): bool
    {
        return (bool) (PermissionRegistry::all()[$this->permission()]['sensitive'] ?? false);
    }

    /** Columns the user may see (sensitive columns need their own permission). */
    public function visibleColumns(ReportFilters $filters, User $user): array
    {
        return array_filter($this->columns($filters), fn ($column) => ! isset($column['sensitive']) || $user->hasPermission($column['sensitive']));
    }

    /** Sorted, deterministic query wrapper used by the table and the export. */
    public function sortedRows(ReportFilters $filters): Builder
    {
        $columns = $this->columns($filters);
        [$defaultSort, $defaultDirection] = $this->defaultSort();
        $sort = $filters->sort && isset($columns[$filters->sort]) && ($columns[$filters->sort]['sortable'] ?? true) ? $filters->sort : $defaultSort;
        $direction = $filters->sort ? $filters->direction : $defaultDirection;
        $tieBreaker = array_key_first($columns);

        $query = DB::query()->fromSub($this->rows($filters), 'report_rows')->orderByRaw('"'.$sort.'" '.$direction.' NULLS LAST');
        if ($tieBreaker && $tieBreaker !== $sort) {
            $query->orderBy($tieBreaker);
        }

        return $query;
    }

    /** @return array<string, mixed> catalogue entry */
    public function meta(): array
    {
        return [
            'key' => $this->key(), 'title' => $this->title(), 'title_ur' => $this->titleUr(), 'section' => $this->section(),
            'description' => $this->description(), 'permission' => $this->permission(), 'sensitive' => $this->isSensitive(),
        ];
    }

    // ---- shared helpers for report definitions ----

    protected function period(Builder|EloquentBuilder $query, ReportFilters $filters, string $column): Builder|EloquentBuilder
    {
        return $query->whereBetween($column, [$filters->from, $filters->to]);
    }

    protected function stores(Builder|EloquentBuilder $query, ReportFilters $filters, string $column): Builder|EloquentBuilder
    {
        return $query->whereIn($column, $filters->storeIds);
    }

    /** SQL expression that buckets a business date by the chosen grouping. */
    protected function bucket(string $column, ?string $groupBy): string
    {
        return match ($groupBy) {
            'week' => "to_char(date_trunc('week', {$column}), 'YYYY-MM-DD')",
            'month' => "to_char(date_trunc('month', {$column}), 'YYYY-MM')",
            'quarter' => "to_char(date_trunc('quarter', {$column}), 'YYYY-\"Q\"Q')",
            'year' => "to_char(date_trunc('year', {$column}), 'YYYY')",
            default => "to_char({$column}, 'YYYY-MM-DD')",
        };
    }

    protected function pct(string $numerator, string $denominator): string
    {
        return "CASE WHEN ({$denominator}) = 0 THEN NULL ELSE ROUND(100.0 * ({$numerator}) / ({$denominator}), 2) END";
    }
}
