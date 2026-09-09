<?php

declare(strict_types=1);

namespace App\Domain\Sales;

/**
 * The bottom of a bill. Every field is whole rupiah.
 *
 * The invariant these numbers must always keep:
 * subtotal - discountTotal + serviceChargeTotal + taxTotal + rounding = grandTotal
 * when prices exclude tax, and
 * subtotal - discountTotal + rounding = grandTotal when they include it,
 * with serviceChargeTotal and taxTotal carved out of that same amount.
 */
final readonly class OrderTotals
{
    public function __construct(
        public int $subtotal = 0,
        public int $itemDiscountTotal = 0,
        public int $billDiscountTotal = 0,
        public int $serviceChargeTotal = 0,
        public int $taxTotal = 0,
        public int $rounding = 0,
        public int $grandTotal = 0,
    ) {}

    /**
     * Item and bill discounts together, which is what the receipt prints.
     */
    public function discountTotal(): int
    {
        return $this->itemDiscountTotal + $this->billDiscountTotal;
    }

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return [
            'subtotal' => $this->subtotal,
            'discount_total' => $this->discountTotal(),
            'service_charge_total' => $this->serviceChargeTotal,
            'tax_total' => $this->taxTotal,
            'rounding' => $this->rounding,
            'grand_total' => $this->grandTotal,
        ];
    }
}
