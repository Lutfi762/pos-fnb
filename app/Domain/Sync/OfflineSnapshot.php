<?php

declare(strict_types=1);

namespace App\Domain\Sync;

use App\Domain\Catalog\MenuCatalog;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Modifier;
use App\Domain\Catalog\Models\ModifierGroup;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Identity\Models\Outlet;
use App\Domain\Sales\Models\DiningTable;
use App\Domain\Sales\Models\Order;
use App\Domain\Sales\Models\PaymentMethod;
use App\Domain\Sales\ShiftRegister;
use App\Domain\Sales\TableMap;
use App\Domain\Sales\TaxProfile;
use App\Domain\Sync\Models\Device;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Everything a terminal needs to keep selling with no connection.
 *
 * Fetched whenever the till is online and kept in the browser. It is a copy
 * for ringing up only: prices on it are what the cashier sees, but the server
 * rebuilds every line from its own menu when the sale is synced.
 */
class OfflineSnapshot
{
    public function __construct(
        private readonly MenuCatalog $menu,
        private readonly ShiftRegister $shifts,
        private readonly TableMap $tables,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function for(Outlet $outlet, User $user, Device $device): array
    {
        $profile = TaxProfile::fromOutlet($outlet);
        $shift = $this->shifts->current($outlet, $user);
        $cash = PaymentMethod::query()->where('is_active', true)->where('type', 'cash')->orderBy('sort_order')->first();
        $prefix = Str::upper(Str::substr((string) $device->getKey(), -4));

        return [
            'version' => 1,
            'generated_at' => now()->toIso8601String(),
            'outlet' => [
                'id' => $outlet->getKey(),
                'code' => $outlet->code,
                'name' => $outlet->name,
                'address' => $outlet->address,
                'receipt_header' => $outlet->receipt_header ?? $outlet->name,
                'receipt_footer' => $outlet->receipt_footer,
                'tax_bps' => $profile->taxBps,
                'service_bps' => $profile->serviceChargeBps,
                'price_includes_tax' => $profile->priceIncludesTax,
                'cash_rounding' => $profile->cashRounding,
                'tax_percent' => $profile->taxPercent(),
                'service_percent' => $profile->serviceChargePercent(),
            ],
            'device' => [
                'id' => $device->getKey(),
                'prefix' => $prefix,
                // The last number this terminal was given today, so numbers it
                // mints offline carry on from there instead of starting over.
                'last_number' => $this->lastNumber($outlet, $prefix),
            ],
            'user' => [
                'id' => $user->getKey(),
                'name' => $user->name,
            ],
            'shift' => $shift === null ? null : [
                'id' => $shift->getKey(),
                'opened_at' => $shift->opened_at->toIso8601String(),
                'opening_cash' => $shift->opening_cash,
            ],
            'cash_method' => $cash === null ? null : [
                'id' => $cash->getKey(),
                'name' => $cash->name,
            ],
            'categories' => $this->menu->categories($outlet)->map(fn (Category $category): array => [
                'id' => $category->getKey(),
                'name' => $category->name,
            ])->values()->all(),
            'products' => $this->menu->products($outlet)->map(fn (Product $product): array => [
                'id' => $product->getKey(),
                'name' => $product->name,
                'category_id' => $product->category_id,
                'sku' => $product->sku,
                'variants' => $product->variants->map(fn (ProductVariant $variant): array => [
                    'id' => $variant->getKey(),
                    'name' => $variant->name,
                    'sku' => $variant->sku,
                    'price' => $variant->priceFor($outlet),
                    'is_default' => (bool) $variant->is_default,
                ])->values()->all(),
                'groups' => $product->modifierGroups->map(fn (ModifierGroup $group): array => [
                    'id' => $group->getKey(),
                    'name' => $group->name,
                    'min' => (int) $group->min_select,
                    'max' => (int) $group->max_select,
                    'modifiers' => $group->modifiers->map(fn (Modifier $modifier): array => [
                        'id' => $modifier->getKey(),
                        'name' => $modifier->name,
                        'price' => (int) $modifier->price,
                    ])->values()->all(),
                ])->values()->all(),
            ])->values()->all(),
            'tables' => $this->tables->forOutlet($outlet)->map(fn (DiningTable $table): array => [
                'id' => $table->getKey(),
                'name' => $table->name,
                'area' => $table->area,
            ])->values()->all(),
        ];
    }

    /**
     * @return array{date: string, sequence: int}
     */
    private function lastNumber(Outlet $outlet, string $prefix): array
    {
        $date = now()->format('ymd');
        $last = Order::query()
            ->where('outlet_id', $outlet->getKey())
            ->where('number', 'like', $outlet->code.'-'.$prefix.'-'.$date.'-%')
            ->orderByDesc('number')
            ->value('number');

        return [
            'date' => $date,
            'sequence' => is_string($last) ? (int) Str::afterLast($last, '-') : 0,
        ];
    }
}
