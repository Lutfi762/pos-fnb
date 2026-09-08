<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Modifier;
use App\Domain\Catalog\Models\ModifierGroup;
use App\Domain\Catalog\Models\OutletVariantPrice;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Identity\Models\Outlet;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CatalogSeeder extends Seeder
{
    /**
     * Menu, prices, and modifiers for the demo outlets.
     *
     * Prices below are the base price. Only the Senopati outlet gets an
     * override, so the per-outlet pricing path is exercised by the demo data.
     */
    public function run(): void
    {
        $menu = [
            ['Kopi', 'kitchen' => 'bar', 'items' => [
                ['Kopi Susu Gula Aren', [['Reguler', 25000], ['Besar', 30000]]],
                ['Americano', [['Panas', 22000], ['Dingin', 25000]]],
                ['Cappuccino', [['Reguler', 28000]]],
            ]],
            ['Non Kopi', 'kitchen' => 'bar', 'items' => [
                ['Matcha Latte', [['Reguler', 30000]]],
                ['Coklat Panas', [['Reguler', 27000]]],
            ]],
            ['Makanan', 'kitchen' => 'kitchen', 'items' => [
                ['Nasi Goreng Kampung', [['Reguler', 38000]]],
                ['Roti Bakar Coklat', [['Reguler', 24000]]],
            ]],
        ];

        $sugar = $this->modifierGroup('Tingkat Gula', 1, 1, [
            ['Normal', 0], ['Sedikit', 0], ['Tanpa Gula', 0],
        ]);

        $topping = $this->modifierGroup('Topping', 0, 3, [
            ['Extra Shot', 8000], ['Boba', 6000], ['Keju', 5000],
        ]);

        $senopati = Outlet::where('code', 'SNP')->first();

        foreach ($menu as $order => $group) {
            $category = Category::updateOrCreate(
                ['slug' => Str::slug($group[0])],
                ['name' => $group[0], 'sort_order' => $order, 'is_active' => true],
            );

            foreach ($group['items'] as $position => [$name, $variants]) {
                $product = Product::updateOrCreate(
                    ['name' => $name],
                    [
                        'category_id' => $category->id,
                        'station' => $group['kitchen'],
                        'sort_order' => $position,
                        'is_active' => true,
                    ],
                );

                $product->modifierGroups()->syncWithoutDetaching(
                    $group['kitchen'] === 'bar' ? [$sugar->id, $topping->id] : [],
                );

                foreach ($variants as $index => [$variantName, $price]) {
                    $variant = ProductVariant::updateOrCreate(
                        ['product_id' => $product->id, 'name' => $variantName],
                        [
                            'base_price' => $price,
                            'is_default' => $index === 0,
                            'is_active' => true,
                            'sort_order' => $index,
                        ],
                    );

                    // Senopati sits in a pricier neighbourhood: add 10 percent,
                    // rounded up to the nearest 500 rupiah.
                    if ($senopati !== null) {
                        OutletVariantPrice::updateOrCreate(
                            ['outlet_id' => $senopati->id, 'product_variant_id' => $variant->id],
                            [
                                'price' => (int) (ceil($price * 1.1 / 500) * 500),
                                'is_available' => true,
                            ],
                        );
                    }
                }
            }
        }
    }

    /**
     * @param  array<int, array{0: string, 1: int}>  $modifiers
     */
    private function modifierGroup(string $name, int $min, int $max, array $modifiers): ModifierGroup
    {
        $group = ModifierGroup::updateOrCreate(
            ['name' => $name],
            ['min_select' => $min, 'max_select' => $max, 'is_active' => true],
        );

        foreach ($modifiers as $index => [$modifierName, $price]) {
            Modifier::updateOrCreate(
                ['modifier_group_id' => $group->id, 'name' => $modifierName],
                ['price' => $price, 'sort_order' => $index, 'is_active' => true],
            );
        }

        return $group;
    }
}
