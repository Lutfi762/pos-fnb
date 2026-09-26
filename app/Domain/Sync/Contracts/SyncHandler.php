<?php

declare(strict_types=1);

namespace App\Domain\Sync\Contracts;

use App\Domain\Sync\SyncContext;

/**
 * Applies one kind of command queued by an offline terminal.
 */
interface SyncHandler
{
    /**
     * Validation rules for the command's own fields, on top of the envelope.
     *
     * @return array<string, mixed>
     */
    public function rules(): array;

    /**
     * Apply the command. Runs inside a transaction that is rolled back on any
     * exception, so a half-applied command never exists.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed> Fields returned to the device and kept for replays.
     */
    public function apply(SyncContext $context, array $payload): array;
}
