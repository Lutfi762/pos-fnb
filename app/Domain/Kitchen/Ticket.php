<?php

declare(strict_types=1);

namespace App\Domain\Kitchen;

use App\Domain\Kitchen\Enums\Station;
use App\Domain\Kitchen\Enums\TicketColumn;
use App\Domain\Kitchen\Enums\Urgency;
use App\Domain\Sales\Models\DiningTable;
use App\Domain\Sales\Models\Order;
use App\Domain\Sales\Models\OrderItem;
use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * One card on the board: everything from one bill that one station has to make.
 *
 * A ticket is derived, never stored. Its column is the least advanced line it
 * holds, so a card only reaches Siap once every plate on it is plated.
 */
class Ticket
{
    /**
     * @param  list<array{name: string, quantity: int, modifiers: list<string>, note: string|null}>  $lines
     */
    public function __construct(
        public readonly string $orderId,
        public readonly string $number,
        public readonly string $where,
        public readonly Station $station,
        public readonly TicketColumn $column,
        public readonly DateTimeInterface $sentAt,
        public readonly int $targetSeconds,
        public readonly array $lines,
    ) {}

    /**
     * @param  Collection<int, OrderItem>  $items
     */
    public static function fromItems(Order $order, Station $station, mixed $items): self
    {
        $column = collect(TicketColumn::cases())
            ->first(fn (TicketColumn $c): bool => $items->contains(
                fn (OrderItem $item): bool => $item->status === $c->status(),
            )) ?? TicketColumn::New;

        return new self(
            orderId: (string) $order->getKey(),
            number: (string) $order->number,
            where: $order->diningTable instanceof DiningTable
                ? $order->diningTable->name
                : $order->type->label(),
            station: $station,
            column: $column,
            sentAt: $items->min('sent_at') ?? $order->placed_at ?? now(),

            // The whole ticket is judged by its slowest plate: a steak does not
            // become late because the side salad next to it was quick.
            targetSeconds: (int) $items->max('prep_minutes') * 60,

            lines: array_values($items->map(fn (OrderItem $item): array => [
                'name' => trim($item->product_name.' '.($item->variant_name ?? '')),
                'quantity' => (int) $item->quantity,
                'modifiers' => array_values($item->modifiers->pluck('modifier_name')->all()),
                'note' => $item->note,
            ])->all()),
        );
    }

    /**
     * Seconds spent so far. Counted from the send, not the order: a second
     * round fired an hour into a meal starts its own clock.
     */
    public function elapsed(): int
    {
        return max(0, now()->getTimestamp() - $this->sentAt->getTimestamp());
    }

    public function urgency(): Urgency
    {
        return Urgency::forElapsed($this->elapsed(), $this->targetSeconds);
    }

    /**
     * Key the board uses for the card, and the board's FLIP animation uses to
     * recognise the same card in its new column.
     */
    public function key(): string
    {
        return $this->orderId.'-'.$this->station->value;
    }
}
