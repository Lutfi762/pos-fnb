<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

use App\Models\DomainModel;
use Database\Factories\ModifierFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Modifier extends DomainModel
{
    /** @use HasFactory<ModifierFactory> */
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ModifierGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(ModifierGroup::class, 'modifier_group_id');
    }

    protected static function newFactory(): ModifierFactory
    {
        return ModifierFactory::new();
    }
}
