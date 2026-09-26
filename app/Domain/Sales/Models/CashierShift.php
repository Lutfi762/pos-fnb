<?php

declare(strict_types=1);

namespace App\Domain\Sales\Models;

use App\Domain\Identity\Models\Outlet;
use App\Models\DomainModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CashierShift extends DomainModel
{
    protected function casts(): array
    {
        return [
            'opening_cash' => 'integer',
            'closing_cash' => 'integer',
            'expected_cash' => 'integer',
            'cash_difference' => 'integer',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
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
    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * @return HasMany<CashMovement, $this>
     */
    public function cashMovements(): HasMany
    {
        return $this->hasMany(CashMovement::class);
    }

    public function isOpen(): bool
    {
        return $this->closed_at === null;
    }
}
