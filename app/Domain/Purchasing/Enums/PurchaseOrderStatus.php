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
}
