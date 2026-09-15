<?php

declare(strict_types=1);

use App\Domain\Reporting\DateRange;
use App\Domain\Reporting\Enums\Preset;

it('covers whole days at both ends', function (): void {
    $range = DateRange::of('2026-03-01', '2026-03-07');

    expect($range->from->format('Y-m-d H:i:s'))->toBe('2026-03-01 00:00:00')
        ->and($range->to->format('Y-m-d H:i:s'))->toBe('2026-03-07 23:59:59')
        ->and($range->days())->toBe(7);
});

it('swaps dates typed backwards instead of returning nothing', function (): void {
    $range = DateRange::of('2026-03-07', '2026-03-01');

    expect($range->from->format('Y-m-d'))->toBe('2026-03-01')
        ->and($range->to->format('Y-m-d'))->toBe('2026-03-07');
});

it('counts a single day as one day', function (): void {
    expect(DateRange::of('2026-03-01', '2026-03-01')->days())->toBe(1);
});

it('steps back by its own length for the comparison period', function (): void {
    $previous = DateRange::of('2026-03-08', '2026-03-14')->previous();

    expect($previous->from->format('Y-m-d'))->toBe('2026-03-01')
        ->and($previous->to->format('Y-m-d'))->toBe('2026-03-07');
});

it('lists every date in between', function (): void {
    expect(DateRange::of('2026-03-01', '2026-03-03')->dates())
        ->toBe(['2026-03-01', '2026-03-02', '2026-03-03']);
});

it('reads the week as a rolling seven days', function (): void {
    expect(Preset::Week->range()->days())->toBe(7)
        ->and(Preset::Today->range()->days())->toBe(1);
});
