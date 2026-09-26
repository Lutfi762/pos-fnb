<?php

declare(strict_types=1);

namespace App\Domain\Sync\Models;

use App\Domain\Identity\Models\Outlet;
use App\Domain\Sync\Enums\SyncStatus;
use App\Models\DomainModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncOperation extends DomainModel
{
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'result' => 'array',
            'status' => SyncStatus::class,
            'attempts' => 'integer',
            'sequence' => 'integer',
            'client_created_at' => 'datetime',
            'processed_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Device, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
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
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
