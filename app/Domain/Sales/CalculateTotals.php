<?php

declare(strict_types=1);

namespace App\Domain\Sales;

use App\Domain\Sales\Enums\DiscountType;

/**
 * Turns a list of cart lines into the numbers printed at the bottom of a bill.
 *
 * Pure: no database, no clock, no session. That is what makes it testable to
 * the rupiah, and this is the one class in the app where a rounding mistake
 * costs somebody real money.
 *
 * Order of operations, which is the part everyone gets wrong:
 *   1. each line: (price + modifiers) x qty, then its own discount
 *   2. subtotal = sum of the line nets
 *   3. bill discount comes off the subtotal
 *   4. service charge on what is left
 *   5. PB1 on (what is left + service charge), because service charge is taxed
 *   6. cash rounding, last, on the grand total
 */
class CalculateTotals
{
    /**
     * @param  list<CartLine>  $lines
     * @param  int  $billDiscountValue  Percent points or rupiah, per the type.
     */
    public function handle(
        array $lines,
        TaxProfile $profile,
        ?DiscountType $billDiscountType = null,
        int $billDiscountValue = 0,
    ): OrderTotals {
        $subtotal = 0;
        $itemDiscountTotal = 0;

        foreach ($lines as $line) {
            $subtotal += $line->net();
            $itemDiscountTotal += $line->discount();
        }

        $billDiscount = $billDiscountType?->deductionFor($subtotal, $billDiscountValue) ?? 0;
        $base = $subtotal - $billDiscount;

        [$serviceCharge, $tax, $grand] = $profile->priceIncludesTax
            ? $this->carveOut($base, $profile)
            : $this->addOn($base, $profile);

        $rounding = $this->roundingFor($grand, $profile->cashRounding);

        return new OrderTotals(
            subtotal: $subtotal,
            itemDiscountTotal: $itemDiscountTotal,
            billDiscountTotal: $billDiscount,
            serviceChargeTotal: $serviceCharge,
            taxTotal: $tax,
            rounding: $rounding,
            grandTotal: $grand + $rounding,
        );
    }

    /**
     * Menu prices already contain service charge and PB1. The guest pays the
     * menu price; the two components are only carved out for reporting.
     *
     * Tax is taken as the remainder rather than computed on its own, so the
     * three parts always add back up to the exact amount charged.
     *
     * @return array{int, int, int}
     */
    private function carveOut(int $base, TaxProfile $profile): array
    {
        if ($base <= 0) {
            return [0, 0, max(0, $base)];
        }

        $grossFactor = (10000 + $profile->serviceChargeBps) * (10000 + $profile->taxBps);
        $net = (int) round($base * 10000 * 10000 / $grossFactor);
        $serviceCharge = (int) round($net * $profile->serviceChargeBps / 10000);

        return [$serviceCharge, $base - $net - $serviceCharge, $base];
    }

    /**
     * Menu prices are net. Service charge and PB1 are added on top, and PB1
     * is charged on the service charge as well.
     *
     * @return array{int, int, int}
     */
    private function addOn(int $base, TaxProfile $profile): array
    {
        if ($base <= 0) {
            return [0, 0, max(0, $base)];
        }

        $serviceCharge = (int) round($base * $profile->serviceChargeBps / 10000);
        $tax = (int) round(($base + $serviceCharge) * $profile->taxBps / 10000);

        return [$serviceCharge, $tax, $base + $serviceCharge + $tax];
    }

    /**
     * Cash rounding to the nearest step, e.g. 100 turns 47.250 into 47.300.
     * Returns the adjustment, which may be negative.
     */
    private function roundingFor(int $grand, int $step): int
    {
        if ($step <= 1 || $grand <= 0) {
            return 0;
        }

        return ((int) round($grand / $step) * $step) - $grand;
    }
}
