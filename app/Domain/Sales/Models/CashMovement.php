<?php

declare(strict_types=1);

namespace App\Domain\Sales\Models;

use App\Domain\Identity\Models\Outlet;
use App\Models\DomainModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cash in or out of a drawer that is not a sale.
 */
class CashMovement extends DomainModel
{
    public const IN = 'in';

    public const OUT = 'out';

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'moved_at' => 'datetime',
        ];
    }

    /**
     * Positive for cash put in, negative for cash taken out.
     */
    public function signedAmount(): int
    {
        return $this->direction === self::OUT ? -$this->amount : $this->amount;
    }

    /**
     * @return BelongsTo<CashierShift, $this>
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(CashierShift::class, 'cashier_shift_id');
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
}
