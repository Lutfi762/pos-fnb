<?php

declare(strict_types=1);

namespace App\Domain\Sync\Enums;

enum SyncStatus: string
{
    case Pending = 'pending';
    case Applied = 'applied';
    case Conflict = 'conflict';
    case Failed = 'failed';
    // A manager looked at a failed or conflicting command and set it aside.
    // It no longer holds up the commands queued behind it on that device.
    case Dismissed = 'dismissed';

    public function label(): string
    {
        return __('sync.status.'.$this->value);
    }

    /**
     * A failed operation may be retried; a conflict needs a human to look.
     */
    public function isRetryable(): bool
    {
        return in_array($this, [self::Pending, self::Failed], true);
    }

    /**
     * Statuses that stop later commands from the same device being applied.
     *
     * @return list<string>
     */
    public static function blocking(): array
    {
        return [self::Pending->value, self::Failed->value, self::Conflict->value];
    }

    /**
     * A command someone has to look at before the device can carry on.
     */
    public function needsAttention(): bool
    {
        return in_array($this, [self::Failed, self::Conflict], true);
    }
}
