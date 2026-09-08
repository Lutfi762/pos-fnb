<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

use App\Models\UlidPivot;

class ProductModifierGroup extends UlidPivot
{
    protected $table = 'modifier_group_product';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }
}
