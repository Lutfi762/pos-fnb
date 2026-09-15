<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use App\Domain\Reporting\Enums\Preset;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * A stretch of days in the outlet's own timezone.
 *
 * Reports are read by people who think in opening hours, not in UTC. The
 * boundaries are therefore inclusive whole days: "1 to 7 March" means from
 * the first minute of the 1st to the last minute of the 7th.
 */
final readonly class DateRange
{
    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
    ) {}

    /**
     * Build from two dates, widening each to a whole day and swapping them
     * when they arrive backwards.
     */
    public static function of(DateTimeInterface|string $from, DateTimeInterface|string $to): self
    {
        $start = CarbonImmutable::parse($from)->startOfDay();
        $end = CarbonImmutable::parse($to)->endOfDay();

        return $start->greaterThan($end)
            ? new self($end->startOfDay(), $start->endOfDay())
            : new self($start, $end);
    }

    public static function fromPreset(Preset $preset): self
    {
        return $preset->range();
    }

    /**
     * Whole days covered, counting both ends.
     */
    public function days(): int
    {
        return (int) $this->from->startOfDay()->diffInDays($this->to->startOfDay()) + 1;
    }

    /**
     * The same length of time, ending where this range starts. Used to answer
     * "up or down from last time" without the caller doing date arithmetic.
     */
    public function previous(): self
    {
        $days = $this->days();

        return new self(
            $this->from->subDays($days)->startOfDay(),
            $this->from->subDay()->endOfDay(),
        );
    }

    /**
     * Every date in the range as Y-m-d, so a chart can show a day that sold
     * nothing rather than skipping over it.
     *
     * @return list<string>
     */
    public function dates(): array
    {
        $dates = [];
        $cursor = $this->from->startOfDay();

        while ($cursor->lessThanOrEqualTo($this->to)) {
            $dates[] = $cursor->format('Y-m-d');
            $cursor = $cursor->addDay();
        }

        return $dates;
    }

    public function label(): string
    {
        return $this->from->isSameDay($this->to)
            ? $this->from->translatedFormat('d M Y')
            : $this->from->translatedFormat('d M').' - '.$this->to->translatedFormat('d M Y');
    }
}
