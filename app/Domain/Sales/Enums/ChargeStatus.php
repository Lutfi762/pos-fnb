<?php

declare(strict_types=1);

namespace App\Domain\Sales\Enums;

enum ChargeStatus: string
{
    // The code is on the screen and the guest has not paid yet.
    case Pending = 'pending';

    // Paid and put against the bill.
    case Paid = 'paid';

    // Paid, but the bill was already settled another way. The money is real
    // and has to go back to the guest; a manager sees it on the review page.
    case Unapplied = 'unapplied';

    case Expired = 'expired';
    case Cancelled = 'cancelled';

    // The gateway would not issue a code.
    case Failed = 'failed';

    public function label(): string
    {
        return __('qris.status.'.$this->value);
    }

    public function isOpen(): bool
    {
        return $this === self::Pending;
    }

    /**
     * Finished without money moving.
     */
    public function isDead(): bool
    {
        return in_array($this, [self::Expired, self::Cancelled, self::Failed], true);
    }
}
