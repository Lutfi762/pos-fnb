<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Identity\CurrentOutlet;
use App\Domain\Reporting\DateRange;
use App\Domain\Reporting\Models\ReportExport;
use App\Domain\Reporting\ReportCatalog;
use App\Domain\Reporting\ReportDocuments;
use App\Domain\Reporting\ReportExports;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Report downloads. Managers and owners only: these files carry cost,
 * margin, and cash figures, and leave the building.
 */
class ReportExportController
{
    public function __invoke(Request $request, string $type, ReportExports $exports, CurrentOutlet $current): Response|RedirectResponse
    {
        $data = $request->validate([
            'format' => ['required', 'in:'.implode(',', ReportDocuments::FORMATS)],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d'],
            'status' => ['nullable', 'string', 'max:20'],
            'cashier' => ['nullable', 'integer'],
            'method' => ['nullable', 'string', 'max:60'],
            'q' => ['nullable', 'string', 'max:60'],
        ]);

        abort_unless(in_array($type, ReportCatalog::TYPES, true), 404);

        $range = DateRange::of($data['from'], $data['to']);

        // A year is the most anyone needs in one file; past that it is a backup.
        abort_if($range->days() > 366, 422, __('reports.invalid_range'));

        $result = $exports->request($request->user(), $current->getOrFail(), $type, $data['format'], $range, array_filter([
            'status' => $data['status'] ?? null,
            'cashier' => $data['cashier'] ?? null,
            'method' => $data['method'] ?? null,
            'search' => $data['q'] ?? null,
        ], fn ($value): bool => $value !== null && $value !== ''));

        if ($result instanceof ReportExport) {
            return redirect()->route('reports.index')->with('export_queued', $result->getKey());
        }

        return $result;
    }

    /**
     * A queued export, for the person who asked for it, while it lasts.
     */
    public function download(Request $request, ReportExport $export): Response
    {
        abort_unless($export->getAttribute('user_id') === $request->user()?->getKey(), 404);
        abort_unless($export->downloadable() && Storage::disk('local')->exists((string) $export->getAttribute('path')), 410);

        return Storage::disk('local')->download((string) $export->getAttribute('path'), (string) $export->getAttribute('filename'), [
            'Cache-Control' => 'no-store',
        ]);
    }
}
