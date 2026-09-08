<?php

declare(strict_types=1);

namespace App\Domain\Sales\Enums;

enum OrderItemStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Preparing = 'preparing';
    case Ready = 'ready';
    case Served = 'served';
    case Voided = 'voided';

    public function label(): string
    {
        return __('orders.item_status.'.$this->value);
    }
}
