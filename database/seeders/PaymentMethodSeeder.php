<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Sales\Models\PaymentMethod;
use Illuminate\Database\Seeder;

class PaymentMethodSeeder extends Seeder
{
    public function run(): void
    {
        $methods = [
            ['name' => 'Tunai', 'type' => 'cash', 'opens_cash_drawer' => true, 'needs_reference' => false, 'fee_percent' => 0],
            ['name' => 'QRIS', 'type' => 'qris', 'opens_cash_drawer' => false, 'needs_reference' => true, 'fee_percent' => 0.7],
            ['name' => 'Kartu Debit', 'type' => 'card', 'opens_cash_drawer' => false, 'needs_reference' => true, 'fee_percent' => 1],
            ['name' => 'Kartu Kredit', 'type' => 'card', 'opens_cash_drawer' => false, 'needs_reference' => true, 'fee_percent' => 2.5],
            ['name' => 'Transfer Bank', 'type' => 'transfer', 'opens_cash_drawer' => false, 'needs_reference' => true, 'fee_percent' => 0],
        ];

        foreach ($methods as $index => $method) {
            PaymentMethod::updateOrCreate(
                ['name' => $method['name']],
                $method + ['is_active' => true, 'sort_order' => $index],
            );
        }
    }
}
