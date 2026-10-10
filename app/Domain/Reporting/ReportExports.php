<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use App\Domain\Audit\Audit;
use App\Domain\Identity\Models\Outlet;
use App\Domain\Reporting\Jobs\GenerateReportExport;
use App\Domain\Reporting\Models\ReportExport;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hands out report files: straight away when the report is small, through
 * the queue when it is not, so a year of bills never times out a request.
 * Every export is written to the audit log, because a file of sales leaves
 * the building.
 */
class ReportExports
{
    public function __construct(
        private readonly ReportCatalog $catalog,
        private readonly ReportDocuments $documents,
        private readonly Audit $audit,
    ) {}

    public function threshold(): int
    {
        return (int) config('reports.queue_threshold', 2000);
    }

    /**
     * The file now, or the queued export to fetch later.
     *
     * @param  array<string, mixed>  $filters
     */
    public function request(User $user, Outlet $outlet, string $type, string $format, DateRange $range, array $filters = []): Response|ReportExport
    {
        abort_unless(in_array($type, ReportCatalog::TYPES, true) && in_array($format, ReportDocuments::FORMATS, true), 404);

        $rows = $this->catalog->count($type, $outlet, $range, $filters);

        if ($rows > $this->threshold()) {
            $export = ReportExport::create([
                'user_id' => $user->getKey(),
                'outlet_id' => $outlet->getKey(),
                'type' => $type,
                'format' => $format,
                'from_date' => $range->fromDate(),
                'to_date' => $range->toDate(),
                'filters' => $filters,
                'status' => ReportExport::QUEUED,
                'rows' => $rows,
            ]);

            $this->record($user, $outlet, $type, $format, $range, $rows, queued: true);

            GenerateReportExport::dispatch($export->getKey());

            return $export->refresh();
        }

        $table = $this->catalog->build($type, $outlet, $range, $filters);
        $this->record($user, $outlet, $type, $format, $range, count($table->rows), queued: false);

        return $this->documents->download($table, $format, $this->basename($type, $outlet, $range));
    }

    /**
     * Build a queued export's file. Called by the job.
     */
    public function generate(ReportExport $export): void
    {
        $export->update(['status' => ReportExport::RUNNING]);

        $outlet = $export->outlet()->withTrashed()->firstOrFail();
        $range = $export->range();
        $table = $this->catalog->build($export->getAttribute('type'), $outlet, $range, $export->filters ?? []);
        $file = $this->documents->file($table, $export->getAttribute('format'));

        $name = $this->basename($export->getAttribute('type'), $outlet, $range).'.'.$file['extension'];
        $path = 'exports/'.$export->getKey().'.'.$file['extension'];

        Storage::disk('local')->put($path, $file['body']);

        $export->update([
            'status' => ReportExport::DONE,
            'rows' => count($table->rows),
            'path' => $path,
            'filename' => $name,
            'completed_at' => now(),
            'expires_at' => now()->addHours((int) config('reports.export_hours', 24)),
        ]);
    }

    /**
     * Delete files past their day. The rows stay, marked expired.
     *
     * @return int files removed
     */
    public function prune(): int
    {
        $removed = 0;

        ReportExport::query()
            ->where('status', ReportExport::DONE)
            ->where('expires_at', '<', now())
            ->each(function (ReportExport $export) use (&$removed): void {
                if ($export->getAttribute('path') !== null) {
                    Storage::disk('local')->delete($export->getAttribute('path'));
                    $removed++;
                }

                $export->update(['status' => ReportExport::EXPIRED, 'path' => null]);
            });

        return $removed;
    }

    public function basename(string $type, Outlet $outlet, DateRange $range): string
    {
        return Str::slug($this->catalog->title($type)).'-'.Str::lower((string) $outlet->getAttribute('code')).'-'.$range->from->format('Ymd').'-'.$range->to->format('Ymd');
    }

    private function record(User $user, Outlet $outlet, string $type, string $format, DateRange $range, int $rows, bool $queued): void
    {
        $this->audit->record('report.exported', after: [
            'type' => $type,
            'format' => $format,
            'from' => $range->fromDate(),
            'to' => $range->toDate(),
            'rows' => $rows,
            'queued' => $queued,
        ], user: $user, outletId: $outlet->getKey());
    }
}
