<?php

declare(strict_types=1);

use App\Domain\Sales\CalculateTotals;
use App\Domain\Sales\CartLine;
use App\Domain\Sales\Enums\DiscountType;
use App\Domain\Sales\Models\Order;
use App\Domain\Sales\TaxProfile;

function totals(array $lines, TaxProfile $profile, ?DiscountType $type = null, int $value = 0)
{
    return (new CalculateTotals)->handle($lines, $profile, $type, $value);
}

/** Prices net of tax, no service charge, no PB1. The simplest bill there is. */
function plain(): TaxProfile
{
    return new TaxProfile(priceIncludesTax: false);
}

it('adds up a plain bill', function (): void {
    $result = totals([
        new CartLine(unitPrice: 25000, quantity: 2),
        new CartLine(unitPrice: 15000),
    ], plain());

    expect($result->subtotal)->toBe(65000)
        ->and($result->grandTotal)->toBe(65000);
});

it('charges paid modifiers once per unit', function (): void {
    // Extra shot 8.000 on two cups: 25.000 + 8.000 = 33.000 each.
    $result = totals([
        new CartLine(unitPrice: 25000, quantity: 2, modifierTotal: 8000),
    ], plain());

    expect($result->subtotal)->toBe(66000);
});

it('treats a free modifier as free', function (): void {
    $result = totals([
        new CartLine(unitPrice: 25000, modifierTotal: 0),
    ], plain());

    expect($result->subtotal)->toBe(25000);
});

it('takes a percent discount off one line only', function (): void {
    $result = totals([
        new CartLine(unitPrice: 20000, quantity: 2, discountType: DiscountType::Percent, discountValue: 10),
        new CartLine(unitPrice: 30000),
    ], plain());

    // 40.000 - 4.000 = 36.000, plus 30.000 untouched.
    expect($result->itemDiscountTotal)->toBe(4000)
        ->and($result->subtotal)->toBe(66000);
});

it('takes a rupiah discount off one line', function (): void {
    $result = totals([
        new CartLine(unitPrice: 25000, discountType: DiscountType::Amount, discountValue: 5000),
    ], plain());

    expect($result->itemDiscountTotal)->toBe(5000)
        ->and($result->subtotal)->toBe(20000);
});

it('never lets a line discount push a line below zero', function (): void {
    $result = totals([
        new CartLine(unitPrice: 10000, discountType: DiscountType::Amount, discountValue: 50000),
    ], plain());

    expect($result->itemDiscountTotal)->toBe(10000)
        ->and($result->subtotal)->toBe(0)
        ->and($result->grandTotal)->toBe(0);
});

it('applies a bill discount after the line discounts', function (): void {
    $result = totals(
        [new CartLine(unitPrice: 50000, discountType: DiscountType::Amount, discountValue: 10000)],
        plain(),
        DiscountType::Percent,
        10,
    );

    // Line: 50.000 - 10.000 = 40.000. Bill: 10 percent of 40.000, not of 50.000.
    expect($result->subtotal)->toBe(40000)
        ->and($result->billDiscountTotal)->toBe(4000)
        ->and($result->discountTotal())->toBe(14000)
        ->and($result->grandTotal)->toBe(36000);
});

it('charges PB1 and service charge on top when prices are net', function (): void {
    $profile = new TaxProfile(taxBps: 1100, serviceChargeBps: 500, priceIncludesTax: false);

    $result = totals([new CartLine(unitPrice: 100000)], $profile);

    // Service 5.000, then PB1 11 percent of 105.000 = 11.550.
    expect($result->serviceChargeTotal)->toBe(5000)
        ->and($result->taxTotal)->toBe(11550)
        ->and($result->grandTotal)->toBe(116550);
});

it('taxes the service charge, not just the food', function (): void {
    $withService = new TaxProfile(taxBps: 1100, serviceChargeBps: 500, priceIncludesTax: false);
    $withoutService = new TaxProfile(taxBps: 1100, priceIncludesTax: false);

    $taxed = totals([new CartLine(unitPrice: 100000)], $withService)->taxTotal;
    $untaxed = totals([new CartLine(unitPrice: 100000)], $withoutService)->taxTotal;

    expect($taxed)->toBeGreaterThan($untaxed);
});

it('carves PB1 and service charge out of a price that already includes them', function (): void {
    $profile = new TaxProfile(taxBps: 1100, serviceChargeBps: 500, priceIncludesTax: true);

    $result = totals([new CartLine(unitPrice: 116550)], $profile);

    // The guest still pays the menu price; the parts are only reported.
    expect($result->grandTotal)->toBe(116550)
        ->and($result->serviceChargeTotal)->toBe(5000)
        ->and($result->taxTotal)->toBe(11550);
});

it('keeps the carved out parts adding back up to the exact amount charged', function (): void {
    $profile = new TaxProfile(taxBps: 1100, serviceChargeBps: 500, priceIncludesTax: true);

    // Awkward amounts on purpose: these are where the rupiah goes missing.
    foreach ([1, 7, 999, 12345, 33333, 47251, 1000001] as $amount) {
        $result = totals([new CartLine(unitPrice: $amount)], $profile);

        $net = $result->grandTotal - $result->serviceChargeTotal - $result->taxTotal;

        expect($net + $result->serviceChargeTotal + $result->taxTotal)
            ->toBe($result->grandTotal, "parts do not add up for {$amount}");
    }
});

it('rounds cash to the nearest step, up and down', function (): void {
    $profile = new TaxProfile(priceIncludesTax: false, cashRounding: 100);

    $up = totals([new CartLine(unitPrice: 47251)], $profile);
    $down = totals([new CartLine(unitPrice: 47249)], $profile);
    $exact = totals([new CartLine(unitPrice: 47200)], $profile);

    expect($up->rounding)->toBe(49)->and($up->grandTotal)->toBe(47300)
        ->and($down->rounding)->toBe(-49)->and($down->grandTotal)->toBe(47200)
        ->and($exact->rounding)->toBe(0)->and($exact->grandTotal)->toBe(47200);
});

it('leaves the total alone when rounding is off', function (): void {
    $result = totals([new CartLine(unitPrice: 47251)], plain());

    expect($result->rounding)->toBe(0)->and($result->grandTotal)->toBe(47251);
});

it('keeps the printed invariant on a full bill', function (): void {
    $profile = new TaxProfile(taxBps: 1100, serviceChargeBps: 500, priceIncludesTax: false, cashRounding: 100);

    $result = totals([
        new CartLine(unitPrice: 25000, quantity: 3, modifierTotal: 6000),
        new CartLine(unitPrice: 38000, discountType: DiscountType::Percent, discountValue: 15),
    ], $profile, DiscountType::Amount, 5000);

    $rebuilt = $result->subtotal
        - $result->billDiscountTotal
        + $result->serviceChargeTotal
        + $result->taxTotal
        + $result->rounding;

    expect($rebuilt)->toBe($result->grandTotal)
        ->and($result->grandTotal % 100)->toBe(0);
});

it('returns zeroes for an empty cart', function (): void {
    $result = totals([], new TaxProfile(taxBps: 1100, serviceChargeBps: 500, cashRounding: 100));

    expect($result->subtotal)->toBe(0)
        ->and($result->taxTotal)->toBe(0)
        ->and($result->serviceChargeTotal)->toBe(0)
        ->and($result->rounding)->toBe(0)
        ->and($result->grandTotal)->toBe(0);
});

it('caps a percent discount at 100 percent', function (): void {
    $result = totals([new CartLine(unitPrice: 20000)], plain(), DiscountType::Percent, 150);

    expect($result->billDiscountTotal)->toBe(20000)
        ->and($result->grandTotal)->toBe(0);
});

it('reads percentages off an order snapshot, not the outlet', function (): void {
    $order = new Order([
        'tax_percent' => '11.00',
        'service_charge_percent' => '5.00',
        'price_includes_tax' => false,
    ]);

    $profile = TaxProfile::fromOrder($order, cashRounding: 100);

    expect($profile->taxBps)->toBe(1100)
        ->and($profile->serviceChargeBps)->toBe(500)
        ->and($profile->priceIncludesTax)->toBeFalse()
        ->and($profile->cashRounding)->toBe(100);
});
