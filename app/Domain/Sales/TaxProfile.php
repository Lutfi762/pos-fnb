<?php

declare(strict_types=1);

namespace App\Domain\Sales;

use App\Domain\Identity\Models\Outlet;
use App\Domain\Sales\Models\Order;

/**
 * The four outlet settings that decide what a guest actually pays.
 *
 * An order snapshots them at ring-up time, so this can be built either from
 * the live outlet (new order) or from the order itself (reprint, refund).
 * Percentages are held as basis points to keep the maths in integers.
 */
final readonly class TaxProfile
{
    public function __construct(
        public int $taxBps = 0,
        public int $serviceChargeBps = 0,
        public bool $priceIncludesTax = true,
        public int $cashRounding = 0,
    ) {}

    public static function fromOutlet(Outlet $outlet): self
    {
        return new self(
            taxBps: self::bps($outlet->tax_percent),
            serviceChargeBps: self::bps($outlet->service_charge_percent),
            priceIncludesTax: (bool) $outlet->price_includes_tax,
            cashRounding: (int) $outlet->cash_rounding,
        );
    }

    /**
     * Rebuild the profile an order was rung up with, ignoring later changes
     * to the outlet settings.
     */
    public static function fromOrder(Order $order, int $cashRounding = 0): self
    {
        return new self(
            taxBps: self::bps($order->tax_percent),
            serviceChargeBps: self::bps($order->service_charge_percent),
            priceIncludesTax: (bool) $order->price_includes_tax,
            cashRounding: $cashRounding,
        );
    }

    public function taxPercent(): float
    {
        return $this->taxBps / 100;
    }

    public function serviceChargePercent(): float
    {
        return $this->serviceChargeBps / 100;
    }

    /**
     * "11.00" or 11.0 becomes 1100 basis points.
     */
    private static function bps(string|float|int|null $percent): int
    {
        return (int) round(((float) $percent) * 100);
    }
}
