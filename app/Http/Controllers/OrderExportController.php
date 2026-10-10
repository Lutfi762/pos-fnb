<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\Audit;
use App\Domain\Identity\CurrentOutlet;
use App\Domain\Reporting\ReportDocuments;
use App\Domain\Sales\Models\Order;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * One bill as PDF or Excel: a receipt in a form a guest's office accepts.
 * Anyone at the outlet may produce one for that outlet's bills.
 */
class OrderExportController
{
    public function __invoke(Request $request, Order $order, string $format, ReportDocuments $documents, CurrentOutlet $current, Audit $audit): Response
    {
        abort_unless(in_array($format, ReportDocuments::FORMATS, true), 404);
        abort_unless($order->outlet_id === $current->getOrFail()->getKey(), 404);

        $audit->record('order.exported', $order, after: ['format' => $format], user: $request->user(), outletId: $order->outlet_id);

        return $documents->downloadOrder($order, $format);
    }
}
