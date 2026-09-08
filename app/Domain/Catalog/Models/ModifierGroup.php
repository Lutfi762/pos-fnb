<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

use App\Models\DomainModel;
use Database\Factories\ModifierGroupFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ModifierGroup extends DomainModel
{
    /** @use HasFactory<ModifierGroupFactory> */
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'min_select' => 'integer',
            'max_select' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Modifier, $this>
     */
    public function modifiers(): HasMany
    {
        return $this->hasMany(Modifier::class)->orderBy('sort_order');
    }

    /**
     * A group with min_select of at least one forces the cashier to choose.
     */
    public function isRequired(): bool
    {
        return $this->min_select > 0;
    }

    protected static function newFactory(): ModifierGroupFactory
    {
        return ModifierGroupFactory::new();
    }
}
