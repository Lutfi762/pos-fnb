<?php

declare(strict_types=1);

namespace App\Domain\Sync;

use RuntimeException;

/**
 * A queued command that cannot be applied without a person deciding what the
 * right outcome is, such as an edit made to an order another terminal changed.
 */
class SyncConflict extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $context  Extra fields for the device, such as the current revision.
     */
    public function __construct(string $message, public readonly array $context = [])
    {
        parent::__construct($message);
    }
}
