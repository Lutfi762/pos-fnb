<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

/**
 * The headline numbers for one stretch of days. Every field is whole rupiah.
 *
 * "Net sales" is the line an owner compares month to month: what was charged,
 * minus the parts that were never theirs to keep. Tax is collected for the
 * state and service charge is collected for the staff, so both come out
 * before the number anyone celebrates.
 */
final readonly class SalesSummary
{
    public function __construct(
        public int $orders = 0,
        public int $gross = 0,
        public int $netSales = 0,
        public int $discounts = 0,
        public int $tax = 0,
        public int $serviceCharge = 0,
        public int $cost = 0,
        public int $guests = 0,
        public int $refunds = 0,
        public int $refunded = 0,
        public int $refundedNet = 0,
        public int $waste = 0,
    ) {}

    /**
     * Net sales less the net part of refunds given back in the same days.
     * A refund is dated the day the money went back, not the day of the bill.
     */
    public function netAfterRefunds(): int
    {
        return $this->netSales - $this->refundedNet;
    }

    /**
     * Net sales less the cost of the ingredients that went out the door.
     */
    public function grossProfit(): int
    {
        return $this->netSales - $this->cost;
    }

    /**
     * Margin in basis points, so the caller can print one decimal without
     * carrying a float around. Zero sales is zero margin, not a division error.
     */
    public function marginBps(): int
    {
        return $this->netSales === 0
            ? 0
            : (int) round($this->grossProfit() * 10000 / $this->netSales);
    }

    /**
     * Average bill. The number that tells an owner whether upselling worked.
     */
    public function averageOrder(): int
    {
        return $this->orders === 0 ? 0 : (int) round($this->gross / $this->orders);
    }

    /**
     * Spend per guest, which is the one a restaurant compares across outlets.
     */
    public function averageGuest(): int
    {
        return $this->guests === 0 ? 0 : (int) round($this->gross / $this->guests);
    }

    /**
     * Change against another period, in basis points. Growth from nothing has
     * no percentage, so it reads as zero rather than infinity.
     */
    public function changeBps(self $previous): int
    {
        return $previous->netSales === 0
            ? 0
            : (int) round(($this->netSales - $previous->netSales) * 10000 / $previous->netSales);
    }
}
