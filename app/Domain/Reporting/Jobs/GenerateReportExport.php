<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Jobs;

use App\Domain\Reporting\Models\ReportExport;
use App\Domain\Reporting\ReportExports;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Build one large export in the background.
 */
class GenerateReportExport implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 600;

    public function __construct(public readonly string $exportId) {}

    public function handle(ReportExports $exports): void
    {
        $export = ReportExport::query()->find($this->exportId);

        if (! $export instanceof ReportExport || $export->status === ReportExport::DONE) {
            return;
        }

        $exports->generate($export);
    }

    public function failed(Throwable $exception): void
    {
        ReportExport::query()->whereKey($this->exportId)->update([
            'status' => ReportExport::FAILED,
            'error' => mb_substr($exception->getMessage(), 0, 250),
        ]);
    }
}
