<?php

declare(strict_types=1);

use App\Support\Money;

it('formats whole rupiah with a thousands separator', function (int $amount, string $expected): void {
    expect(Money::format($amount))->toBe($expected);
})->with([
    [0, 'Rp0'],
    [1000, 'Rp1.000'],
    [25000, 'Rp25.000'],
    [1234567, 'Rp1.234.567'],
    [-25000, '-Rp25.000'],
]);

it('formats without a prefix when asked', function (): void {
    expect(Money::format(25000, withPrefix: false))->toBe('25.000');
});

it('parses user typed amounts back to an integer', function (string $input, int $expected): void {
    expect(Money::parse($input))->toBe($expected);
})->with([
    ['Rp25.000', 25000],
    ['25000', 25000],
    ['Rp 1.234.567', 1234567],
    ['', 0],
    ['abc', 0],
    ['-25.000', -25000],
]);

it('survives a format then parse round trip', function (): void {
    foreach ([0, 500, 25000, 1234567] as $amount) {
        expect(Money::parse(Money::format($amount)))->toBe($amount);
    }
});
