<?php

declare(strict_types=1);

namespace App\Domain\Sales\Models;

use App\Domain\Identity\Models\Outlet;
use App\Models\DomainModel;
use Database\Factories\DiningTableFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class DiningTable extends DomainModel
{
    /** @use HasFactory<DiningTableFactory> */
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'seats' => 'integer',
            'pos_x' => 'integer',
            'pos_y' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * @return BelongsTo<Outlet, $this>
     */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    protected static function newFactory(): DiningTableFactory
    {
        return DiningTableFactory::new();
    }
}
