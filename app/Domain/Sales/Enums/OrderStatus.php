<?php

declare(strict_types=1);

namespace App\Domain\Sales\Enums;

enum OrderStatus: string
{
    case Draft = 'draft';
    case Open = 'open';
    case Paid = 'paid';
    case Voided = 'voided';

    public function label(): string
    {
        return __('orders.status.'.$this->value);
    }

    /**
     * Only an open or draft order may still be edited by a cashier.
     */
    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::Open], true);
    }
}
