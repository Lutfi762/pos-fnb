<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Identity\Models\Outlet;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Models\InventoryItem;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Models\Unit;
use App\Domain\Inventory\MoveStock;
use App\Domain\Purchasing\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Seeder;

class InventorySeeder extends Seeder
{
    /**
     * Ingredients, suppliers, and opening stock for the demo outlets.
     *
     * Opening balances go through MoveStock rather than straight into the
     * levels table, so the demo ledger reads the same way a real one does and
     * the average cost lands on each item.
     */
    public function run(MoveStock $stock): void
    {
        $units = $this->units();
        $outlets = Outlet::orderBy('code')->get();
        $keeper = User::where('email', 'gudang@meja.test')->first();

        // [name, sku, category, unit, reorder point, opening qty, cost per unit]
        $ingredients = [
            ['Biji Kopi Arabika', 'BJ-ARB', 'Bahan Baku', 'kg', 5, 12, 185000],
            ['Biji Kopi Robusta', 'BJ-RBS', 'Bahan Baku', 'kg', 5, 8, 120000],
            ['Susu Segar', 'SS-FRS', 'Bahan Baku', 'l', 20, 36, 18000],
            ['Susu Kental Manis', 'SS-KTL', 'Bahan Baku', 'kg', 4, 6, 26000],
            ['Gula Aren Cair', 'GL-ARN', 'Bahan Baku', 'l', 6, 10, 45000],
            ['Gula Pasir', 'GL-PSR', 'Bahan Baku', 'kg', 10, 25, 16000],
            ['Bubuk Matcha', 'BB-MTC', 'Bahan Baku', 'kg', 2, 3, 320000],
            ['Bubuk Coklat', 'BB-CKL', 'Bahan Baku', 'kg', 3, 2, 145000],
            ['Sirup Vanila', 'SR-VNL', 'Bahan Baku', 'l', 2, 4, 95000],
            ['Teh Hitam Celup', 'TH-HTM', 'Bahan Baku', 'pcs', 100, 400, 1200],
            ['Es Batu Kristal', 'ES-KRS', 'Bahan Baku', 'kg', 15, 40, 4000],
            ['Mie Instan Goreng', 'MI-GRG', 'Bahan Baku', 'pcs', 24, 60, 3500],
            ['Telur Ayam', 'TL-AYM', 'Bahan Baku', 'kg', 5, 9, 30000],
            ['Kentang Beku', 'KT-BKU', 'Bahan Baku', 'kg', 6, 14, 38000],
            ['Gelas Plastik 16 oz', 'KM-G16', 'Kemasan', 'pcs', 200, 900, 900],
            ['Tutup Gelas 16 oz', 'KM-T16', 'Kemasan', 'pcs', 200, 850, 450],
            ['Sedotan Kertas', 'KM-SDT', 'Kemasan', 'pcs', 300, 250, 350],
            ['Kertas Struk 80mm', 'KM-STR', 'Kemasan', 'pcs', 20, 12, 7500],
        ];

        foreach ($ingredients as [$name, $sku, $category, $unit, $reorder, $opening, $cost]) {
            $item = InventoryItem::updateOrCreate(
                ['sku' => $sku],
                [
                    'unit_id' => $units[$unit],
                    'name' => $name,
                    'category' => $category,
                    'is_active' => true,
                ],
            );

            foreach ($outlets as $index => $outlet) {
                $stock->setReorderPoint($outlet, $item, $reorder);

                // Only the first delivery is seeded; re-running must not keep
                // piling stock onto a balance that is already there.
                $alreadyStocked = StockMovement::where('outlet_id', $outlet->getKey())
                    ->where('inventory_item_id', $item->getKey())
                    ->exists();

                if ($alreadyStocked) {
                    continue;
                }

                // Later outlets open with less, so the restock list has
                // something on it at every outlet the demo is browsed from.
                $quantity = round($opening * (1 - ($index * 0.35)), 3);

                if ($quantity <= 0) {
                    continue;
                }

                $stock->record(
                    outlet: $outlet,
                    item: $item,
                    type: StockMovementType::Purchase,
                    quantity: $quantity,
                    user: $keeper,
                    unitCost: $cost,
                    note: 'Stok awal',
                );
            }
        }

        $this->suppliers();
    }

    /**
     * Base units. Everything is stocked and costed in one of these.
     *
     * @return array<string, string> unit code to id
     */
    private function units(): array
    {
        $units = [
            'kg' => 'Kilogram',
            'g' => 'Gram',
            'l' => 'Liter',
            'ml' => 'Mililiter',
            'pcs' => 'Buah',
        ];

        $ids = [];

        foreach ($units as $code => $name) {
            $ids[$code] = Unit::updateOrCreate(['code' => $code], ['name' => $name])->id;
        }

        return $ids;
    }

    private function suppliers(): void
    {
        $suppliers = [
            ['name' => 'CV Kopi Nusantara', 'contact_name' => 'Bayu Pratama', 'phone' => '0812-1100-2201', 'payment_term_days' => 14],
            ['name' => 'PT Sari Susu Segar', 'contact_name' => 'Rina Kusuma', 'phone' => '0813-4400-7788', 'payment_term_days' => 7],
            ['name' => 'Toko Bahan Kue Makmur', 'contact_name' => 'Hendra Wijaya', 'phone' => '0857-9911-3322', 'payment_term_days' => 0],
            ['name' => 'UD Kemasan Jaya', 'contact_name' => 'Siti Rahayu', 'phone' => '0878-2255-6644', 'payment_term_days' => 30],
        ];

        foreach ($suppliers as $supplier) {
            Supplier::updateOrCreate(
                ['name' => $supplier['name']],
                $supplier + [
                    'email' => null,
                    'address' => null,
                    'is_active' => true,
                ],
            );
        }
    }
}
