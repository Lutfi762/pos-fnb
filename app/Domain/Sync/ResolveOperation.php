<?php

declare(strict_types=1);

namespace App\Domain\Sync;

use App\Domain\Audit\Audit;
use App\Domain\Sync\Enums\SyncStatus;
use App\Domain\Sync\Models\SyncOperation;
use App\Models\User;

/**
 * A manager's decision about a command a terminal could not apply.
 */
class ResolveOperation
{
    public function __construct(private readonly Audit $audit) {}

    /**
     * Set a failed or conflicting command aside so the device can carry on.
     * Nothing it asked for is applied. The reason is kept on the row and in
     * the audit log, because a dismissed sale is money someone should explain.
     */
    public function dismiss(SyncOperation $operation, User $manager, string $reason): SyncOperation
    {
        abort_unless($operation->status->needsAttention(), 409, __('sync.not_dismissable'));
        abort_if(trim($reason) === '', 422, __('sync.reason_required'));

        $before = ['status' => $operation->status->value, 'error' => $operation->error];

        $operation->update([
            'status' => SyncStatus::Dismissed,
            'resolved_by' => $manager->getKey(),
            'resolved_at' => now(),
            'resolution_note' => trim($reason),
        ]);

        $this->audit->record('sync.dismissed', $operation, $before, ['status' => SyncStatus::Dismissed->value], trim($reason), $manager);

        return $operation;
    }

    /**
     * Mark an applied command's warning as dealt with, such as a short paid
     * bill whose difference was taken from the guest or written off.
     */
    public function acknowledge(SyncOperation $operation, User $manager, string $note): SyncOperation
    {
        abort_unless($operation->status === SyncStatus::Applied && $operation->warning !== null, 409);
        abort_if(trim($note) === '', 422, __('sync.reason_required'));

        $operation->update([
            'resolved_by' => $manager->getKey(),
            'resolved_at' => now(),
            'resolution_note' => trim($note),
        ]);

        $this->audit->record('sync.acknowledged', $operation, ['warning' => $operation->warning], null, trim($note), $manager);

        return $operation;
    }
}
