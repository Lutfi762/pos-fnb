<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Models;

use App\Models\DomainModel;
use Database\Factories\UnitFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Unit extends DomainModel
{
    /** @use HasFactory<UnitFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @return HasMany<InventoryItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(InventoryItem::class);
    }

    protected static function newFactory(): UnitFactory
    {
        return UnitFactory::new();
    }
}
