<?php

declare(strict_types=1);

namespace App\Domain\Sync\Enums;

enum SyncStatus: string
{
    case Pending = 'pending';
    case Applied = 'applied';
    case Conflict = 'conflict';
    case Failed = 'failed';

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
}
