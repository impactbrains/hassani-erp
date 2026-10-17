<?php

namespace App\Domain\Reporting;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;

/**
 * A report described by an array in one of the catalogue files. Keeps each
 * report's definition, SQL and presentation together in a few lines.
 */
final class DefinedReport extends Report
{
    /** @param array<string, mixed> $d */
    public function __construct(private readonly array $d)
    {
    }

    public function key(): string
    {
        return $this->d['key'];
    }

    public function title(): string
    {
        return $this->d['title'];
    }

    public function titleUr(): ?string
    {
        return $this->d['title_ur'] ?? null;
    }

    public function section(): string
    {
        return $this->d['section'];
    }

    public function permission(): string
    {
        return $this->d['permission'];
    }

    public function description(): string
    {
        return $this->d['description'];
    }

    public function definition(): array
    {
        return $this->d['definition'];
    }

    public function filters(): array
    {
        return $this->d['filters'] ?? ['period', 'stores'];
    }

    public function groupByOptions(): array
    {
        return $this->d['group_by'] ?? [];
    }

    public function columns(ReportFilters $filters): array
    {
        $columns = $this->d['columns'];

        return is_callable($columns) ? $columns($filters) : $columns;
    }

    public function rows(ReportFilters $filters): Builder|EloquentBuilder
    {
        return ($this->d['rows'])($filters, $this);
    }

    public function summary(ReportFilters $filters): array
    {
        return isset($this->d['summary']) ? ($this->d['summary'])($filters, $this) : [];
    }

    public function chart(ReportFilters $filters): ?array
    {
        return isset($this->d['chart']) ? ($this->d['chart'])($filters, $this) : null;
    }

    public function insights(ReportFilters $filters, array $summary): array
    {
        return isset($this->d['insights']) ? ($this->d['insights'])($filters, $summary) : [];
    }

    public function defaultSort(): array
    {
        return $this->d['sort'] ?? parent::defaultSort();
    }

    public function drillDown(): ?array
    {
        return $this->d['drill'] ?? null;
    }

    public function supportsComparison(): bool
    {
        return (bool) ($this->d['comparison'] ?? false);
    }

    // expose helpers to catalogue closures
    public function bucketSql(string $column, ?string $groupBy): string
    {
        return $this->bucket($column, $groupBy);
    }

    public function pctSql(string $numerator, string $denominator): string
    {
        return $this->pct($numerator, $denominator);
    }
}
