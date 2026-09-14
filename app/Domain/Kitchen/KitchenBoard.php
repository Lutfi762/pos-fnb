<?php

declare(strict_types=1);

namespace App\Domain\Kitchen;

use App\Domain\Identity\Models\Outlet;
use App\Domain\Kitchen\Enums\Station;
use App\Domain\Kitchen\Enums\TicketColumn;
use App\Domain\Sales\Enums\OrderItemStatus;
use App\Domain\Sales\Models\Order;
use App\Domain\Sales\Models\OrderItem;
use Illuminate\Support\Collection;

/**
 * Reads the board.
 *
 * Everything the kitchen sees comes from one query. The screen redraws on
 * every broadcast, so this has to stay a single round trip, eager loaded.
 */
class KitchenBoard
{
    /**
     * Live tickets at an outlet, optionally narrowed to one station.
     *
     * @return Collection<int, Ticket>
     */
    public function tickets(Outlet $outlet, ?Station $station = null): Collection
    {
        $live = array_map(
            fn (TicketColumn $column): string => $column->status()->value,
            TicketColumn::cases(),
        );

        $orders = Order::query()
            ->where('outlet_id', $outlet->getKey())
            ->whereNull('merged_into_order_id')
            ->whereHas('items', fn ($query) => $query->whereIn('status', $live)->whereNull('voided_at'))
            ->with([
                'diningTable',
                'items' => fn ($query) => $query->whereIn('status', $live)->whereNull('voided_at')->orderBy('created_at'),
                'items.modifiers',
            ])
            ->orderBy('placed_at')
            ->get();

        return $orders
            ->flatMap(fn (Order $order): Collection => $order->items
                // A line with no station belongs to whoever is nearest; the
                // kitchen is the safer default, since food goes cold.
                ->groupBy(fn (OrderItem $item): string => $item->station ?? Station::Kitchen->value)
                ->map(fn (Collection $items, string $key): Ticket => Ticket::fromItems(
                    $order,
                    Station::tryFrom($key) ?? Station::Kitchen,
                    $items,
                ))
                ->values())
            ->when(
                $station instanceof Station,
                fn (Collection $tickets): Collection => $tickets->where('station', $station)->values(),
            )
            // Oldest first: the board is read top-left to bottom-right, and the
            // thing closest to being late must be the first thing seen.
            ->sortBy(fn (Ticket $ticket): int => $ticket->sentAt->getTimestamp())
            ->values();
    }

    /**
     * Tickets grouped into the three columns, empty columns included.
     *
     * @return array<string, list<Ticket>>
     */
    public function columns(Outlet $outlet, ?Station $station = null): array
    {
        $tickets = $this->tickets($outlet, $station);

        $columns = [];

        foreach (TicketColumn::cases() as $column) {
            $columns[$column->value] = array_values(
                $tickets->where('column', $column)->all(),
            );
        }

        return $columns;
    }

    /**
     * How many lines are still waiting to be made, for the badge in the nav.
     */
    public function openCount(Outlet $outlet, ?Station $station = null): int
    {
        return OrderItem::query()
            ->whereIn('status', [OrderItemStatus::Sent->value, OrderItemStatus::Preparing->value])
            ->when(
                $station instanceof Station,
                fn ($query) => $query->where('station', $station->value),
            )
            ->whereHas('order', fn ($query) => $query->where('outlet_id', $outlet->getKey()))
            ->count();
    }
}
