<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Enums;

enum PurchaseOrderStatus: string
{
    case Draft = 'draft';
    case Ordered = 'ordered';
    case PartiallyReceived = 'partially_received';
    case Received = 'received';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return __('purchasing.status.'.$this->value);
    }

    /**
     * Received goods already moved stock, so the order is frozen from then on.
     */
    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::Ordered], true);
    }

    /**
     * Goods can only arrive against an order the supplier has actually seen.
     */
    public function isReceivable(): bool
    {
        return in_array($this, [self::Ordered, self::PartiallyReceived], true);
    }

    /** Badge colour, matching the palette the catalog screen already uses. */
    public function color(): string
    {
        return match ($this) {
            self::Draft => 'zinc',
            self::Ordered => 'blue',
            self::PartiallyReceived => 'amber',
            self::Received => 'green',
            self::Cancelled => 'red',
        };
    }
}
