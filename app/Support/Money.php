<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Rupiah is stored as a whole-rupiah integer everywhere in this app.
 * There are no cents in practice, so no decimals are ever printed.
 */
final class Money
{
    /**
     * Format a whole-rupiah amount, e.g. 25000 => "Rp25.000".
     */
    public static function format(int $amount, bool $withPrefix = true): string
    {
        $formatted = number_format(abs($amount), 0, ',', '.');
        $sign = $amount < 0 ? '-' : '';

        return $sign.($withPrefix ? 'Rp' : '').$formatted;
    }

    /**
     * Read a user-typed amount such as "Rp 25.000" or "25000" back into an integer.
     */
    public static function parse(string $value): int
    {
        $digits = preg_replace('/[^0-9-]/', '', $value) ?? '';

        return $digits === '' || $digits === '-' ? 0 : (int) $digits;
    }
}
