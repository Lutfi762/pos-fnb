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

    /**
     * @param  CarbonImmutable|null  $today  the outlet's current trading day; the calendar day when not given
     */
    public function range(?CarbonImmutable $today = null): DateRange
    {
        $today ??= CarbonImmutable::today();

        return match ($this) {
            self::Today => new DateRange($today->startOfDay(), $today->endOfDay()),
            self::Yesterday => new DateRange($today->subDay()->startOfDay(), $today->subDay()->endOfDay()),

            // Rolling seven days including today, not the calendar week: on a
            // Monday morning a calendar week is one lunch service long.
            self::Week => new DateRange($today->subDays(6)->startOfDay(), $today->endOfDay()),

            self::Month => new DateRange($today->startOfMonth(), $today->endOfDay()),
        };
    }
}
