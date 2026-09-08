<?php

declare(strict_types=1);

namespace App\Domain\Sales\Enums;

enum DiscountType: string
{
    case Percent = 'percent';
    case Amount = 'amount';

    public function label(): string
    {
        return __('discounts.type.'.$this->value);
    }

    /**
     * Work out the deduction for a subtotal, in whole rupiah, never below zero
     * and never more than the subtotal itself.
     *
     * @param  int  $value  Percent points, or a rupiah amount.
     * @param  int|null  $maxDeduction  Optional ceiling for percent discounts.
     */
    public function deductionFor(int $subtotal, int $value, ?int $maxDeduction = null): int
    {
        if ($subtotal <= 0 || $value <= 0) {
            return 0;
        }

        $deduction = match ($this) {
            self::Percent => intdiv($subtotal * min($value, 100), 100),
            self::Amount => $value,
        };

        if ($maxDeduction !== null) {
            $deduction = min($deduction, $maxDeduction);
        }

        return min($deduction, $subtotal);
    }
}
