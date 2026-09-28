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
            ['name' => 'Tunai', 'type' => 'cash', 'mode' => PaymentMethod::MANUAL, 'opens_cash_drawer' => true, 'needs_reference' => false, 'fee_percent' => 0],

            // A fresh code per bill from the gateway: nothing to type, nothing to check later.
            ['name' => 'QRIS', 'type' => 'qris', 'mode' => PaymentMethod::GATEWAY_QRIS, 'opens_cash_drawer' => false, 'needs_reference' => false, 'fee_percent' => 0.7],

            // The printed standee, for when the gateway is down or not set up.
            ['name' => 'QRIS Statis', 'type' => 'qris', 'mode' => PaymentMethod::STATIC_QRIS, 'opens_cash_drawer' => false, 'needs_reference' => true, 'fee_percent' => 0.7],

            ['name' => 'Kartu Debit', 'type' => 'card', 'mode' => PaymentMethod::MANUAL, 'opens_cash_drawer' => false, 'needs_reference' => true, 'fee_percent' => 1],
            ['name' => 'Kartu Kredit', 'type' => 'card', 'mode' => PaymentMethod::MANUAL, 'opens_cash_drawer' => false, 'needs_reference' => true, 'fee_percent' => 2.5],
            ['name' => 'Transfer Bank', 'type' => 'transfer', 'mode' => PaymentMethod::MANUAL, 'opens_cash_drawer' => false, 'needs_reference' => true, 'fee_percent' => 0],
        ];

        foreach ($methods as $index => $method) {
            PaymentMethod::updateOrCreate(
                ['name' => $method['name']],
                $method + ['is_active' => true, 'sort_order' => $index],
            );
        }
    }
}
