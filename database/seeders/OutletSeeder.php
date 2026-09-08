<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Identity\Models\Outlet;
use App\Domain\Sales\Models\DiningTable;
use Illuminate\Database\Seeder;

class OutletSeeder extends Seeder
{
    public function run(): void
    {
        $outlets = [
            ['name' => 'Meja Kopi Kemang', 'code' => 'KMG', 'tax_percent' => 11, 'service_charge_percent' => 5],
            ['name' => 'Meja Kopi Senopati', 'code' => 'SNP', 'tax_percent' => 11, 'service_charge_percent' => 5],
            ['name' => 'Meja Kopi Bintaro', 'code' => 'BTR', 'tax_percent' => 11, 'service_charge_percent' => 0],
        ];

        foreach ($outlets as $attributes) {
            $outlet = Outlet::updateOrCreate(
                ['code' => $attributes['code']],
                $attributes + [
                    'price_includes_tax' => true,
                    'cash_rounding' => 100,
                    'receipt_header' => $attributes['name'],
                    'receipt_footer' => 'Terima kasih atas kunjungan Anda.',
                    'is_active' => true,
                ],
            );

            // Eight indoor tables and four outdoor ones per outlet.
            foreach (range(1, 12) as $number) {
                DiningTable::updateOrCreate(
                    ['outlet_id' => $outlet->id, 'name' => 'M'.$number],
                    [
                        'area' => $number > 8 ? 'Outdoor' : 'Indoor',
                        'seats' => $number % 3 === 0 ? 4 : 2,
                        'is_active' => true,
                    ],
                );
            }
        }
    }
}
