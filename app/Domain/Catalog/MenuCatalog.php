<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Identity\Models\Outlet;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * The menu a cashier sees, cached per outlet.
 *
 * The POS reads this on every keystroke of the search box, so it must not hit
 * the database each time. The cache is dropped whenever the menu is edited.
 */
class MenuCatalog
{
    private const TTL = 3600;

    /**
     * Active products with their variants, prices, and modifier groups.
     *
     * @return Collection<int, Product>
     */
    public function products(Outlet $outlet): Collection
    {
        $outletId = $outlet->getKey();

        /** @var Collection<int, Product> */
        return Cache::remember(
            self::keyFor($outletId, 'products'),
            self::TTL,
            fn (): Collection => Product::query()
                ->where('is_active', true)
                ->with([
                    'category',
                    'variants' => fn ($query) => $query->where('is_active', true)->orderBy('sort_order'),
                    'variants.outletPrices' => fn ($query) => $query->where('outlet_id', $outletId),
                    'modifierGroups' => fn ($query) => $query->where('is_active', true),
                    'modifierGroups.modifiers' => fn ($query) => $query->where('is_active', true),
                ])
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
                // A variant with no price anywhere cannot be sold.
                ->filter(fn (Product $product): bool => $product->variants->isNotEmpty())
                ->values(),
        );
    }

    /**
     * @return Collection<int, Category>
     */
    public function categories(Outlet $outlet): Collection
    {
        /** @var Collection<int, Category> */
        return Cache::remember(
            self::keyFor($outlet->getKey(), 'categories'),
            self::TTL,
            fn (): Collection => Category::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
        );
    }

    /**
     * Drop the cached menu for every outlet.
     *
     * Called whenever the catalog changes. Blunt on purpose: the menu is
     * shared across outlets, and a wrong price on screen is worse than one
     * extra query per outlet after an edit.
     */
    public static function flush(): void
    {
        foreach (Outlet::query()->pluck('id') as $outletId) {
            Cache::forget(self::keyFor((string) $outletId, 'products'));
            Cache::forget(self::keyFor((string) $outletId, 'categories'));
        }
    }

    private static function keyFor(mixed $outletId, string $part): string
    {
        return "menu:{$part}:{$outletId}";
    }
}
