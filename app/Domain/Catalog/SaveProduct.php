<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use Illuminate\Support\Facades\DB;

/**
 * Create or update one product together with its variants.
 *
 * Variants are edited as a whole list, so a variant missing from the payload
 * was removed by the user. It is soft deleted rather than erased: past order
 * lines keep pointing at it.
 */
class SaveProduct
{
    /**
     * @param  array{name: string, category_id: string, station: string|null, description: string|null, is_active: bool}  $attributes
     * @param  list<array{id: string|null, name: string, base_price: int, is_active: bool}>  $variants
     */
    public function handle(?Product $product, array $attributes, array $variants): Product
    {
        return DB::transaction(function () use ($product, $attributes, $variants): Product {
            $product = $product instanceof Product
                ? tap($product)->update($attributes)
                : Product::create($attributes);

            $kept = [];

            foreach ($variants as $position => $variant) {
                $row = [
                    'name' => $variant['name'],
                    'base_price' => $variant['base_price'],
                    // The first variant is what the POS preselects.
                    'is_default' => $position === 0,
                    'is_active' => $variant['is_active'],
                    'sort_order' => $position,
                ];

                // The id is guarded, so a new variant cannot go through
                // updateOrCreate: it would try to mass assign the key.
                $saved = $variant['id'] === null
                    ? $product->variants()->create($row)
                    : tap($product->variants()->findOrFail($variant['id']))->update($row);

                $kept[] = $saved->getKey();
            }

            $product->variants()
                ->whereNotIn('id', $kept)
                ->each(fn (ProductVariant $variant) => $variant->delete());

            // The POS reads a cached menu; a stale price is a wrong charge.
            MenuCatalog::flush();

            return $product->load('variants');
        });
    }
}
