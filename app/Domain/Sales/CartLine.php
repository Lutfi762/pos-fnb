<?php

declare(strict_types=1);

namespace App\Domain\Sales;

use App\Domain\Sales\Enums\DiscountType;

/**
 * One line of a bill, reduced to the numbers that matter for money.
 *
 * ponytail: quantity is a whole count. A cafe sells cups and plates, not
 * kilos. Widen to decimal(12,3) here and in the callers if scales arrive.
 */
final readonly class CartLine
{
    /**
     * @param  int  $unitPrice  Menu price of one unit, before modifiers.
     * @param  int  $modifierTotal  Paid modifiers for ONE unit.
     * @param  int  $discountValue  Percent points or rupiah, per the type.
     */
    public function __construct(
        public int $unitPrice,
        public int $quantity = 1,
        public int $modifierTotal = 0,
        public ?DiscountType $discountType = null,
        public int $discountValue = 0,
    ) {}

    /**
     * What the line costs before any discount.
     */
    public function gross(): int
    {
        return max(0, $this->unitPrice + $this->modifierTotal) * max(0, $this->quantity);
    }

    /**
     * The line discount, never more than the line itself.
     */
    public function discount(): int
    {
        return $this->discountType?->deductionFor($this->gross(), $this->discountValue) ?? 0;
    }

    /**
     * What the line costs after its own discount.
     */
    public function net(): int
    {
        return $this->gross() - $this->discount();
    }
}
