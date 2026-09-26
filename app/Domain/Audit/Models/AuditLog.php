<?php

declare(strict_types=1);

namespace App\Domain\Audit\Models;

use App\Domain\Identity\Models\Outlet;
use App\Models\DomainModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line in the record of who changed money, stock, or settings.
 *
 * Append only: nothing in the app updates or deletes a row once written.
 */
class AuditLog extends DomainModel
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Outlet, $this>
     */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
