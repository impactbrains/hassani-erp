<?php

namespace App\Domain\Reporting;

use App\Exceptions\BusinessRuleException;
use App\Jobs\RunReportExport;
use App\Models\ReportExport;
use App\Models\User;
use App\Support\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\Storage;

/**
 * Report exports. Small exports run straight away; larger ones are queued
 * and the user downloads the file from the export list. Files are private,
 * expire after 7 days and every request and download is audited. The full
 * filtered dataset is exported (not just the visible page) with only the
 * columns the user may see.
 */
final class ReportExporter
{
    public const INLINE_LIMIT = 5000;

    public const MAX_ROWS = 500000;

    public function request(User $user, Report $report, ReportFilters $filters): ReportExport
    {
        if (! $user->hasPermission('reports.export')) {
            throw new BusinessRuleException('You do not have permission to export reports.', 'forbidden');
        }
        $running = ReportExport::query()->where('user_id', $user->id)->whereIn('status', ['queued', 'running'])->count();
        if ($running >= 3) {
            throw new BusinessRuleException('You already have 3 exports running. Wait for them to finish.', 'too_many_exports');
        }
        $export = ReportExport::create([
            'user_id' => $user->id, 'report_key' => $report->key(), 'filters' => $filters->toArray() + ['_permission_stores' => $filters->storeIds],
            'status' => 'queued', 'is_sensitive' => $report->isSensitive(), 'expires_at' => now()->addDays(7),
        ]);
        Audit::record('report.exported', "Export of “{$report->title()}” requested", $export, [], ['filters' => $filters->toArray()]);

        $count = (clone $report->rows($filters))->getCountForPagination();
        if ($count <= self::INLINE_LIMIT) {
            $this->run($export);
        } else {
            RunReportExport::dispatch($export->id, CurrentCompany::require());
        }

        return $export->fresh();
    }

    public function run(ReportExport $export): void
    {
        $user = User::query()->findOrFail($export->user_id);
        $report = ReportRegistry::find($export->report_key);
        $export->update(['status' => 'running', 'started_at' => now()]);
        try {
            $input = $export->filters;
            $filters = ReportFilters::fromInput(array_merge($input, ['preset' => 'custom']), $user, $report->permission());
            $columns = array_filter($report->visibleColumns($filters, $user), fn ($c) => $c['type'] !== 'hidden');
            $path = "exports/{$export->company_id}/".$export->id.'-'.str_replace('.', '-', $report->key()).'-'.now()->format('Ymd-His').'.csv';
            $handle = fopen('php://temp', 'w+');
            $writer = new CsvWriter($handle);
            $writer->header(['Report: '.$report->title(), 'Period: '.$filters->from.' to '.$filters->to, 'Exported: '.now()->timezone($filters->timezone)->format('Y-m-d H:i'), 'By: '.$user->name]);
            $writer->header(array_map(fn ($c) => $c['label'], array_values($columns)));
            $rows = 0;
            foreach ($report->sortedRows($filters)->lazy(1000) as $row) {
                if (++$rows > self::MAX_ROWS) {
                    throw new BusinessRuleException('More than '.self::MAX_ROWS.' rows. Narrow the filters and export again.', 'too_large');
                }
                $writer->row($columns, $row);
            }
            rewind($handle);
            Storage::disk('local')->put($path, $handle);
            fclose($handle);
            $export->update(['status' => 'completed', 'row_count' => $rows, 'file_path' => $path, 'file_size' => Storage::disk('local')->size($path), 'completed_at' => now()]);
        } catch (\Throwable $e) {
            $export->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500), 'completed_at' => now()]);
            if (! $e instanceof BusinessRuleException) {
                report($e);
            }
        }
    }

    /** Re-checks permission at download time: access may have been removed since. */
    public function download(User $user, ReportExport $export)
    {
        $report = ReportRegistry::find($export->report_key);
        if ($export->user_id !== $user->id || ! $user->hasPermission('reports.export') || ! ($user->hasPermission($report->permission()) || $user->storeIdsFor($report->permission()) !== [])) {
            throw new BusinessRuleException('This export is not available to you.', 'forbidden');
        }
        if ($export->status !== 'completed' || ! $export->file_path || $export->expires_at?->isPast() || ! Storage::disk('local')->exists($export->file_path)) {
            throw new BusinessRuleException('This export has expired or is not ready.', 'export_unavailable');
        }
        $export->update(['downloaded_at' => now()]);
        Audit::record('report.downloaded', "Export of “{$report->title()}” downloaded", $export);

        return Storage::disk('local')->download($export->file_path, basename($export->file_path), ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function purgeExpired(): int
    {
        $count = 0;
        ReportExport::query()->withoutGlobalScopes()->where('expires_at', '<', now())->whereNotNull('file_path')->each(function (ReportExport $export) use (&$count) {
            Storage::disk('local')->delete($export->file_path);
            $export->forceFill(['file_path' => null, 'status' => 'expired'])->saveQuietly();
            $count++;
        });

        return $count;
    }
}
