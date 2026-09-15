<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Enums;

use App\Domain\Reporting\DateRange;
use Carbon\CarbonImmutable;

/**
 * The four questions an owner actually asks, as buttons.
 *
 * Anything else is typed into the two date fields, so this list stays short
 * rather than growing a case for every possible window.
 */
enum Preset: string
{
    case Today = 'today';
    case Yesterday = 'yesterday';
    case Week = 'week';
    case Month = 'month';

    public function label(): string
    {
        return __('reports.preset.'.$this->value);
    }

    public function range(): DateRange
    {
        $now = CarbonImmutable::now();

        return match ($this) {
            self::Today => new DateRange($now->startOfDay(), $now->endOfDay()),
            self::Yesterday => new DateRange($now->subDay()->startOfDay(), $now->subDay()->endOfDay()),

            // Rolling seven days including today, not the calendar week: on a
            // Monday morning a calendar week is one lunch service long.
            self::Week => new DateRange($now->subDays(6)->startOfDay(), $now->endOfDay()),

            self::Month => new DateRange($now->startOfMonth(), $now->endOfDay()),
        };
    }
}
