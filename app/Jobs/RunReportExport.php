<?php

namespace App\Jobs;

use App\Domain\Reporting\ReportExporter;
use App\Models\ReportExport;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

final class RunReportExport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(public int $exportId, public int $companyId)
    {
    }

    public function handle(ReportExporter $exporter): void
    {
        CurrentCompany::runAs($this->companyId, fn () => $exporter->run(ReportExport::query()->findOrFail($this->exportId)));
    }
}
