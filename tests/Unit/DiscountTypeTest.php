<?php

declare(strict_types=1);

use App\Domain\Sales\Enums\DiscountType;

it('takes a percentage off a subtotal in whole rupiah', function (): void {
    expect(DiscountType::Percent->deductionFor(100_000, 10))->toBe(10_000)
        ->and(DiscountType::Percent->deductionFor(33_333, 10))->toBe(3_333);
});

it('never rounds a percentage up into extra rupiah', function (): void {
    // 7 percent of 1.005 is 70.35, the guest is charged the cheaper 70.
    expect(DiscountType::Percent->deductionFor(1_005, 7))->toBe(70);
});

it('caps a percentage discount at the maximum deduction', function (): void {
    expect(DiscountType::Percent->deductionFor(500_000, 50, 100_000))->toBe(100_000);
});

it('takes a flat amount off a subtotal', function (): void {
    expect(DiscountType::Amount->deductionFor(100_000, 25_000))->toBe(25_000);
});

it('never deducts more than the subtotal', function (): void {
    expect(DiscountType::Amount->deductionFor(10_000, 25_000))->toBe(10_000)
        ->and(DiscountType::Percent->deductionFor(10_000, 200))->toBe(10_000);
});

it('deducts nothing from an empty or negative basket', function (): void {
    expect(DiscountType::Amount->deductionFor(0, 25_000))->toBe(0)
        ->and(DiscountType::Percent->deductionFor(-5_000, 10))->toBe(0)
        ->and(DiscountType::Amount->deductionFor(50_000, 0))->toBe(0);
});
