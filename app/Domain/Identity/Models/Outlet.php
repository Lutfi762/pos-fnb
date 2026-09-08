<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Models\DomainModel;
use App\Models\User;
use Database\Factories\OutletFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Outlet extends DomainModel
{
    /** @use HasFactory<OutletFactory> */
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'tax_percent' => 'decimal:2',
            'service_charge_percent' => 'decimal:2',
            'price_includes_tax' => 'boolean',
            'cash_rounding' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsToMany<User, $this, OutletUser>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->using(OutletUser::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    protected static function newFactory(): OutletFactory
    {
        return OutletFactory::new();
    }
}
