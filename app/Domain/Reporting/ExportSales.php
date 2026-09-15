<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use App\Domain\Identity\Models\Outlet;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The daily figures as a CSV an accountant can open.
 *
 * Streamed rather than built in memory, and separated with a semicolon: an
 * Indonesian Excel reads a comma as a decimal point and would fold every
 * column into one.
 */
class ExportSales
{
    public function __construct(private readonly SalesReport $report) {}

    public function handle(Outlet $outlet, DateRange $range): StreamedResponse
    {
        $rows = $this->report->daily($outlet, $range);
        $filename = 'penjualan-'.$outlet->code.'-'.$range->from->format('Ymd').'-'.$range->to->format('Ymd').'.csv';

        return response()->streamDownload(function () use ($rows): void {
            $handle = fopen('php://output', 'wb');

            if ($handle === false) {
                return;
            }

            // Byte order mark, so Excel opens the file as UTF-8 and Indonesian
            // product names keep their accents.
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, [__('reports.date'), __('reports.orders'), __('reports.net_sales')], ';');

            foreach ($rows as $row) {
                fputcsv($handle, [$row->date, $row->orders, $row->net], ';');
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
