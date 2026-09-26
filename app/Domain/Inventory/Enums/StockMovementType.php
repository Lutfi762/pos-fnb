<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Enums;

enum StockMovementType: string
{
    case Purchase = 'purchase';
    case Sale = 'sale';
    case Waste = 'waste';
    case Adjustment = 'adjustment';
    case Transfer = 'transfer';
    case Count = 'count';
    // Stock put back on the shelf by a refund, such as a sealed bottle returned.
    case Return = 'return';

    public function label(): string
    {
        return __('inventory.movement.'.$this->value);
    }

    /**
     * Types that always reduce stock. Adjustment, transfer, and count can go
     * either way, so their sign comes from the quantity itself.
     */
    public function isOutgoing(): bool
    {
        return in_array($this, [self::Sale, self::Waste], true);
    }
}
