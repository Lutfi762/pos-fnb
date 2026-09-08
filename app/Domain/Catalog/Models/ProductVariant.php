<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

use App\Domain\Identity\Models\Outlet;
use App\Models\DomainModel;
use Database\Factories\ProductVariantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductVariant extends DomainModel
{
    /** @use HasFactory<ProductVariantFactory> */
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'base_price' => 'integer',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return HasMany<OutletVariantPrice, $this>
     */
    public function outletPrices(): HasMany
    {
        return $this->hasMany(OutletVariantPrice::class);
    }

    /**
     * Price for one outlet, falling back to the base price when that outlet
     * has no override. Requires outletPrices to be eager loaded.
     */
    public function priceFor(Outlet|string $outlet): int
    {
        $outletId = $outlet instanceof Outlet ? $outlet->getKey() : $outlet;

        $override = $this->outletPrices->firstWhere('outlet_id', $outletId);

        return $override instanceof OutletVariantPrice
            ? $override->price
            : $this->base_price;
    }

    protected static function newFactory(): ProductVariantFactory
    {
        return ProductVariantFactory::new();
    }
}
