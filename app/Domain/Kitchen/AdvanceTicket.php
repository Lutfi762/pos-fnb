<?php

declare(strict_types=1);

namespace App\Domain\Kitchen;

use App\Domain\Kitchen\Enums\Station;
use App\Domain\Kitchen\Enums\TicketColumn;
use App\Domain\Kitchen\Events\BoardChanged;
use App\Domain\Sales\Enums\OrderItemStatus;
use App\Domain\Sales\Models\Order;
use App\Domain\Sales\Models\OrderItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Moves a ticket between columns and tells every other screen about it.
 *
 * Works on the whole ticket, not a single line: the kitchen taps a card, and
 * a card is all the lines one station owes one bill.
 */
class AdvanceTicket
{
    /**
     * Send an order's lines to their stations. Called once the cashier saves
     * the bill; lines already sent keep their original clock.
     */
    public function send(Order $order): int
    {
        $sent = $order->items()
            ->where('status', OrderItemStatus::Pending->value)
            ->update([
                'status' => OrderItemStatus::Sent->value,
                'sent_at' => now(),
            ]);

        if ($sent > 0) {
            BoardChanged::dispatch((string) $order->outlet_id);
        }

        return $sent;
    }

    /**
     * Move one station's lines on a bill to the next column.
     */
    public function advance(Order $order, Station $station, TicketColumn $to): void
    {
        $this->moveTo($order, $station, $to);
    }

    /**
     * Put a ticket back a column, for the tap that was not meant.
     */
    public function rewind(Order $order, Station $station, TicketColumn $from): void
    {
        $back = $from->previous();

        if ($back instanceof TicketColumn) {
            $this->moveTo($order, $station, $back);
        }
    }

    private function moveTo(Order $order, Station $station, TicketColumn $to): void
    {
        $status = $to->status();

        DB::transaction(function () use ($order, $station, $to, $status): void {
            $items = $order->items()
                ->where(fn ($query) => $station === Station::Kitchen
                    ? $query->where('station', $station->value)->orWhereNull('station')
                    : $query->where('station', $station->value))
                ->whereIn('status', array_map(
                    fn (TicketColumn $column): string => $column->status()->value,
                    TicketColumn::cases(),
                ))
                ->get();

            foreach ($items as $item) {
                $item->update([
                    'status' => $status,

                    // Stamped once. Tapping back and forward again must not
                    // rewrite when the cook actually picked the ticket up.
                    ...$this->stampFor($item, $to),
                ]);
            }
        });

        BoardChanged::dispatch((string) $order->outlet_id);
    }

    /**
     * @return array<string, \DateTimeInterface>
     */
    private function stampFor(OrderItem $item, TicketColumn $to): array
    {
        return match (true) {
            $to === TicketColumn::Preparing && $item->started_at === null => ['started_at' => now()],
            $to === TicketColumn::Ready && $item->ready_at === null => ['ready_at' => now()],
            default => [],
        };
    }
}
