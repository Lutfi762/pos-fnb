<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Ingredient and item quantities as people read them in Indonesia.
 */
final class Quantity
{
    /**
     * Quantities are decimal(12,3) but read as whole numbers most of the
     * time, so trailing zeroes are dropped: 4,000 shows as 4, 4,250 as 4,25,
     * and 1500 as 1.500.
     */
    public static function format(float $quantity): string
    {
        $formatted = number_format($quantity, 3, ',', '.');

        return rtrim(rtrim($formatted, '0'), ',');
    }
}
