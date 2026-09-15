<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use App\Domain\Inventory\Models\RecipeItem;

/**
 * What one serving costs to make, from its recipe.
 *
 * Read once per cart save and cached for that request: a cart of ten lines
 * would otherwise ask the same question ten times. Variants without a recipe
 * cost nothing, which is honest rather than wrong: nobody has told the app
 * what goes into them yet, and guessing would quietly fake a margin.
 */
class RecipeCost
{
    /** @var array<string, int> variant id to cost per serving */
    private array $cache = [];

    /**
     * Cost of one serving of a variant, in whole rupiah.
     */
    public function forVariant(?string $variantId): int
    {
        if ($variantId === null) {
            return 0;
        }

        // Summed in PHP rather than in SQL: a recipe is a handful of rows, and
        // this keeps the multiplication readable and portable across drivers.
        return $this->cache[$variantId] ??= (int) round(
            RecipeItem::query()
                ->with('item')
                ->where('product_variant_id', $variantId)
                ->get()
                ->sum(fn (RecipeItem $row): float => (float) $row->quantity * $row->item->average_cost)
        );
    }
}
