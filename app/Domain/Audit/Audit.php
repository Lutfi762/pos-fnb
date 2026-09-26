<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Identity\CurrentOutlet;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Writes the audit trail.
 *
 * Called from the actions that move money or stock, never from a screen, so a
 * change made through any door leaves the same line behind.
 */
class Audit
{
    public function __construct(
        private readonly CurrentOutlet $current,
        private readonly Request $request,
    ) {}

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function record(
        string $action,
        ?Model $subject = null,
        ?array $before = null,
        ?array $after = null,
        ?string $note = null,
        ?User $user = null,
        ?User $approvedBy = null,
        ?string $outletId = null,
    ): AuditLog {
        $user ??= $this->request->user();

        return AuditLog::create([
            'outlet_id' => $outletId ?? $subject?->getAttribute('outlet_id') ?? $this->current->id(),
            'user_id' => $user instanceof User ? $user->getKey() : null,
            'approved_by' => $approvedBy?->getKey(),
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject === null ? null : (string) $subject->getKey(),
            'before' => $before,
            'after' => $after,
            'note' => $note,
            'ip' => $this->request->ip(),
            'created_at' => now(),
        ]);
    }
}
