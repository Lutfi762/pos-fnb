<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Models;

use App\Domain\Identity\Models\Outlet;
use App\Domain\Reporting\DateRange;
use App\Models\DomainModel;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A report export built in the background.
 *
 * @property string $status
 * @property array<string, mixed>|null $filters
 * @property CarbonImmutable|null $expires_at
 */
class ReportExport extends DomainModel
{
    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const DONE = 'done';

    public const FAILED = 'failed';

    public const EXPIRED = 'expired';

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'rows' => 'integer',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Outlet, $this>
     */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    public function range(): DateRange
    {
        return DateRange::of((string) $this->getAttribute('from_date'), (string) $this->getAttribute('to_date'));
    }

    public function downloadable(): bool
    {
        return $this->status === self::DONE && $this->expires_at?->isFuture() === true && $this->getAttribute('path') !== null;
    }
}
