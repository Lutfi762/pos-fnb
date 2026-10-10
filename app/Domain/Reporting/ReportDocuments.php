<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use App\Domain\Reporting\Tables\Column;
use App\Domain\Reporting\Tables\ReportTable;
use App\Domain\Sales\Models\Order;
use App\Domain\Sales\Models\OrderItem;
use App\Domain\Sales\Models\OrderItemModifier;
use App\Support\Money;
use App\Support\Pdf\PdfRenderer;
use App\Support\Spreadsheet\XlsxWriter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Turns a report or a single bill into the file someone asked for: Excel,
 * or PDF. Without a PDF engine installed, "PDF" is the same document as a
 * page that opens the print dialog, so the browser can save it as PDF.
 */
class ReportDocuments
{
    public const XLSX = 'xlsx';

    public const PDF = 'pdf';

    public const FORMATS = [self::XLSX, self::PDF];

    public function __construct(private readonly PdfRenderer $pdf) {}

    public function pdfAvailable(): bool
    {
        return $this->pdf->available();
    }

    public function xlsx(ReportTable $table): string
    {
        $types = array_map(fn (Column $column): string => $column->type, $table->columns);
        $rows = $table->rows;

        if ($table->totals !== null) {
            $rows[] = $table->totals;
        }

        return (new XlsxWriter)
            ->sheet($table->title, array_map(fn (Column $column): string => $column->label, $table->columns), $types, $rows)
            ->sheet(__('reports.info_sheet'), [__('reports.info_label'), __('reports.info_value')], [XlsxWriter::TEXT, XlsxWriter::TEXT], $this->pairs([__('reports.report') => $table->title] + $table->meta))
            ->toString();
    }

    public function html(ReportTable $table, bool $printable = false): string
    {
        return view('exports.report', [
            'table' => $table,
            'printable' => $printable,
            'landscape' => count($table->columns) > 7,
        ])->render();
    }

    /**
     * Bytes and headers for a report download in the asked format.
     *
     * @return array{body: string, type: string, extension: string}
     */
    public function file(ReportTable $table, string $format): array
    {
        if ($format === self::XLSX) {
            return ['body' => $this->xlsx($table), 'type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'extension' => 'xlsx'];
        }

        if ($this->pdf->available()) {
            return ['body' => $this->pdf->render($this->html($table), 'a4', count($table->columns) > 7 ? 'landscape' : 'portrait'), 'type' => 'application/pdf', 'extension' => 'pdf'];
        }

        return ['body' => $this->html($table, printable: true), 'type' => 'text/html; charset=UTF-8', 'extension' => 'html'];
    }

    public function download(ReportTable $table, string $format, string $basename): Response
    {
        return $this->respond($this->file($table, $format), $basename);
    }

    /**
     * One bill as Excel: its lines, its payments, and the bill itself.
     */
    public function orderXlsx(Order $order): string
    {
        $order->loadMissing(['items.modifiers', 'payments', 'refunds', 'cashier', 'outlet', 'diningTable']);

        $lines = $order->items->map(fn (OrderItem $item): array => [
            $item->product_name,
            $item->variant_name,
            round((float) $item->quantity, 3),
            (int) $item->unit_price,
            $item->modifiers->map(fn (OrderItemModifier $modifier): string => $modifier->getAttribute('modifier_name'))->implode(', '),
            (int) $item->getAttribute('discount_total'),
            (int) $item->line_total,
            $item->status->label(),
        ])->all();

        $payments = $order->payments->map(fn ($payment): array => [
            $payment->method_name,
            (int) $payment->amount,
            $payment->reference,
            $payment->paid_at,
        ])->all();

        return (new XlsxWriter)
            ->sheet(__('orders.line'), [
                __('orders.line'), __('orders.variant'), __('orders.quantity'), __('orders.unit_price'),
                __('orders.modifiers'), __('reports.discounts'), __('orders.line_total'), __('orders.status_label'),
            ], [
                XlsxWriter::TEXT, XlsxWriter::TEXT, XlsxWriter::DECIMAL, XlsxWriter::MONEY,
                XlsxWriter::TEXT, XlsxWriter::MONEY, XlsxWriter::MONEY, XlsxWriter::TEXT,
            ], $lines)
            ->sheet(__('orders.payments'), [__('payments.method'), __('orders.amount'), __('orders.reference'), __('orders.time')], [
                XlsxWriter::TEXT, XlsxWriter::MONEY, XlsxWriter::TEXT, XlsxWriter::DATETIME,
            ], $payments)
            ->sheet(__('reports.info_sheet'), [__('reports.info_label'), __('reports.info_value')], [XlsxWriter::TEXT, XlsxWriter::TEXT], $this->pairs($this->orderFacts($order)))
            ->toString();
    }

    public function orderHtml(Order $order, bool $printable = false): string
    {
        $order->loadMissing(['items.modifiers', 'payments', 'refunds', 'cashier', 'outlet', 'diningTable']);

        return view('exports.order', ['order' => $order, 'printable' => $printable])->render();
    }

    public function downloadOrder(Order $order, string $format): Response
    {
        if ($format === self::XLSX) {
            $file = ['body' => $this->orderXlsx($order), 'type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'extension' => 'xlsx'];
        } elseif ($this->pdf->available()) {
            $file = ['body' => $this->pdf->render($this->orderHtml($order)), 'type' => 'application/pdf', 'extension' => 'pdf'];
        } else {
            $file = ['body' => $this->orderHtml($order, printable: true), 'type' => 'text/html; charset=UTF-8', 'extension' => 'html'];
        }

        return $this->respond($file, 'bill-'.$order->number);
    }

    /**
     * @param  array{body: string, type: string, extension: string}  $file
     */
    private function respond(array $file, string $basename): Response
    {
        // A printable page opens in the tab; files are downloads.
        $disposition = $file['extension'] === 'html' ? 'inline' : 'attachment';

        return response($file['body'], 200, [
            'Content-Type' => $file['type'],
            'Content-Disposition' => $disposition.'; filename="'.$basename.'.'.$file['extension'].'"',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function orderFacts(Order $order): array
    {
        $money = fn (int $amount): string => Money::format($amount);

        return [
            __('orders.number') => $order->number,
            __('reports.outlet') => (string) $order->outlet?->name,
            __('orders.type_label') => $order->type->label().($order->diningTable ? ' '.$order->diningTable->getAttribute('name') : ''),
            __('orders.status_label') => $order->status->label(),
            __('orders.placed_at') => (string) $order->getAttribute('placed_at')?->format('d/m/Y H:i'),
            __('orders.paid_at') => (string) $order->paid_at?->format('d/m/Y H:i'),
            __('orders.cashier') => (string) $order->cashier?->name,
            __('orders.subtotal') => $money((int) $order->getAttribute('subtotal')),
            __('reports.discounts') => $money((int) $order->getAttribute('discount_total')),
            __('reports.service_charge') => $money((int) $order->getAttribute('service_charge_total')),
            __('reports.tax') => $money((int) $order->getAttribute('tax_total')),
            __('orders.rounding') => $money((int) $order->getAttribute('rounding')),
            __('reports.total') => $money((int) $order->getAttribute('grand_total')),
            __('orders.refunds') => $money((int) $order->getAttribute('refunded_total')),
        ];
    }

    /**
     * @param  array<string, string>  $pairs
     * @return list<array{0: string, 1: string}>
     */
    private function pairs(array $pairs): array
    {
        return array_map(fn (string $label, string $value): array => [$label, $value], array_keys($pairs), array_values($pairs));
    }
}
