<?php

declare(strict_types=1);

use App\Domain\Catalog\MenuCatalog;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Modifier;
use App\Domain\Catalog\Models\ModifierGroup;
use App\Domain\Catalog\Models\OutletVariantPrice;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Identity\Models\Outlet;
use Illuminate\Support\Collection;

/*
| The till caches the menu between requests. Laravel only unserializes the
| classes listed in cache.serializable_classes, so this reads the menu back
| through the real database cache store: a class missing from the list turns
| the second page load into an error instead of a menu.
*/
it('reads the cached menu back as models, not incomplete objects', function (): void {
    config(['cache.default' => 'database']);

    $outlet = Outlet::factory()->create();
    $group = ModifierGroup::factory()->create();
    Modifier::factory()->for($group, 'group')->create();
    $product = Product::factory()->for(Category::factory())->create();
    $product->modifierGroups()->attach($group);
    $variant = ProductVariant::factory()->for($product)->create();
    OutletVariantPrice::create(['outlet_id' => $outlet->getKey(), 'product_variant_id' => $variant->getKey(), 'price' => 21_000]);

    $catalog = app(MenuCatalog::class);
    $catalog->products($outlet);
    $catalog->categories($outlet);

    // A fresh instance, as the next request would have.
    $again = new MenuCatalog;
    $products = $again->products($outlet);

    expect($products)->toBeInstanceOf(Collection::class)
        ->and($products->first())->toBeInstanceOf(Product::class)
        ->and($products->first()->variants->first()->priceFor($outlet))->toBe(21_000)
        ->and($products->first()->modifierGroups->first()->modifiers)->toHaveCount(1)
        ->and($again->categories($outlet)->first())->toBeInstanceOf(Category::class);
});
