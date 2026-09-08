<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

use App\Domain\Identity\Models\Outlet;
use App\Models\DomainModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OutletVariantPrice extends DomainModel
{
    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'is_available' => 'boolean',
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
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
