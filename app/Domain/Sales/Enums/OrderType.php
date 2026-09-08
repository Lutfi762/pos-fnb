<?php

declare(strict_types=1);

namespace App\Domain\Sales\Enums;

enum OrderType: string
{
    case DineIn = 'dine_in';
    case TakeAway = 'take_away';
    case Delivery = 'delivery';

    public function label(): string
    {
        return __('orders.type.'.$this->value);
    }

    /**
     * Only dine-in needs a table assigned to it.
     */
    public function needsTable(): bool
    {
        return $this === self::DineIn;
    }
}
