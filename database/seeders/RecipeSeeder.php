<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Inventory\Models\InventoryItem;
use App\Domain\Inventory\Models\RecipeItem;
use Illuminate\Database\Seeder;

class RecipeSeeder extends Seeder
{
    /**
     * What goes into each drink, so the margin report has something to report.
     *
     * ponytail: only the items whose ingredients exist in the demo stock list
     * are costed. A dish with no recipe simply costs nothing, which reads as
     * "nobody has entered it yet" rather than a fake margin. Add the rest when
     * the recipe editor lands.
     */
    public function run(): void
    {
        // Product name => [ingredient sku => quantity per serving]
        $recipes = [
            'Espresso' => ['BJ-ARB' => 0.018],
            'Americano' => ['BJ-ARB' => 0.018, 'ES-KRS' => 0.1],
            'Kopi Susu Gula Aren' => ['BJ-RBS' => 0.02, 'SS-FRS' => 0.15, 'GL-ARN' => 0.03, 'ES-KRS' => 0.12, 'KM-G16' => 1, 'KM-T16' => 1, 'KM-SDT' => 1],
            'Cappuccino' => ['BJ-ARB' => 0.018, 'SS-FRS' => 0.12],
            'Cafe Latte' => ['BJ-ARB' => 0.018, 'SS-FRS' => 0.18],
            'Kopi Tubruk' => ['BJ-RBS' => 0.015, 'GL-PSR' => 0.01],
            'Matcha Latte' => ['BB-MTC' => 0.012, 'SS-FRS' => 0.18, 'GL-PSR' => 0.015],
            'Coklat' => ['BB-CKL' => 0.025, 'SS-FRS' => 0.18],
            'Red Velvet Latte' => ['BB-CKL' => 0.02, 'SS-FRS' => 0.18, 'SR-VNL' => 0.01],
            'Susu Segar' => ['SS-FRS' => 0.25],
            'Es Teh Manis' => ['TH-HTM' => 1, 'GL-PSR' => 0.025, 'ES-KRS' => 0.15],
            'Teh Tawar' => ['TH-HTM' => 1],
            'Lemon Tea' => ['TH-HTM' => 1, 'GL-PSR' => 0.02],
            'Teh Tarik' => ['TH-HTM' => 2, 'SS-KTL' => 0.04],
            'Jus Alpukat' => ['SS-KTL' => 0.05, 'ES-KRS' => 0.1],
            'Soda Gembira' => ['SS-KTL' => 0.05, 'SR-VNL' => 0.02, 'ES-KRS' => 0.12],
            'Indomie Goreng' => ['MI-GRG' => 1],
            'Indomie Kuah' => ['MI-GRG' => 1],
            'Nasi Goreng Kampung' => ['TL-AYM' => 0.06],
            'Nasi Ayam Katsu' => ['TL-AYM' => 0.05],
            'Kentang Goreng' => ['KT-BKU' => 0.2],
            'Roti Bakar Coklat' => ['BB-CKL' => 0.02],
        ];

        $items = InventoryItem::query()->pluck('id', 'sku');

        $products = Product::query()
            ->with('variants')
            ->whereIn('name', array_keys($recipes))
            ->get();

        foreach ($products as $product) {
            foreach ($product->variants as $variant) {
                $factor = $this->sizeFactor($variant);

                foreach ($recipes[$product->name] as $sku => $quantity) {
                    $itemId = $items->get($sku);

                    if ($itemId === null) {
                        continue;
                    }

                    RecipeItem::updateOrCreate(
                        ['product_variant_id' => $variant->getKey(), 'inventory_item_id' => $itemId],
                        ['quantity' => round($quantity * $factor, 3)],
                    );
                }
            }
        }
    }

    /**
     * A large cup holds more than a small one. Size lives in the variant name
     * here because that is where the menu keeps it.
     */
    private function sizeFactor(ProductVariant $variant): float
    {
        return match ($variant->name) {
            'Besar', 'Jumbo', 'Double' => 1.4,
            default => 1.0,
        };
    }
}
