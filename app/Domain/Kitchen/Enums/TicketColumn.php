<?php

declare(strict_types=1);

namespace App\Domain\Kitchen\Enums;

use App\Domain\Sales\Enums\OrderItemStatus;

/**
 * The three columns of the board, and the item status each one holds.
 *
 * The board is a view over order_items, not a table of its own: a ticket is
 * wherever its lines are, so there is never a second place to keep in sync.
 */
enum TicketColumn: string
{
    case New = 'new';
    case Preparing = 'preparing';
    case Ready = 'ready';

    public function status(): OrderItemStatus
    {
        return match ($this) {
            self::New => OrderItemStatus::Sent,
            self::Preparing => OrderItemStatus::Preparing,
            self::Ready => OrderItemStatus::Ready,
        };
    }

    /**
     * The column a tap moves to. Ready is the end of the line here; handing
     * the plate over is the waiter's job, not the board's.
     */
    public function next(): ?self
    {
        return match ($this) {
            self::New => self::Preparing,
            self::Preparing => self::Ready,
            self::Ready => null,
        };
    }

    public function previous(): ?self
    {
        return match ($this) {
            self::New => null,
            self::Preparing => self::New,
            self::Ready => self::Preparing,
        };
    }

    public function label(): string
    {
        return __('kitchen.column.'.$this->value);
    }
}
