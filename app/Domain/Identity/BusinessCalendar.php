<?php

declare(strict_types=1);

namespace App\Domain\Identity;

use App\Domain\Identity\Models\Outlet;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Which trading day a moment belongs to.
 *
 * An outlet's day starts at its own hour in its own timezone, not at
 * midnight in the server's. A bill paid at 01:30 in a bar that opens until
 * 02:00 is last night's sale, and every report, summary, and stock count
 * agrees on that because they all ask here.
 *
 * Scoped to one request or job: outlet settings are read once and reused.
 */
class BusinessCalendar
{
    /** @var array<string, array{zone: string, hour: int}> */
    private array $clocks = [];

    /**
     * The trading day a moment falls in, as Y-m-d.
     */
    public function dateFor(Outlet|string $outlet, CarbonInterface $moment): string
    {
        $clock = $this->clock($outlet);

        return $moment->toImmutable()
            ->setTimezone($clock['zone'])
            ->subHours($clock['hour'])
            ->toDateString();
    }

    /**
     * Today's trading day at the outlet, as a date at midnight.
     */
    public function today(Outlet|string $outlet): CarbonImmutable
    {
        return CarbonImmutable::parse($this->dateFor($outlet, CarbonImmutable::now()));
    }

    /**
     * When a trading day starts and ends, in the app's timezone, for queries
     * against timestamps that have no business date of their own.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function bounds(Outlet|string $outlet, CarbonInterface|string $date): array
    {
        $clock = $this->clock($outlet);
        $day = is_string($date) ? $date : $date->toDateString();

        $start = CarbonImmutable::parse($day, $clock['zone'])->startOfDay()->addHours($clock['hour']);
        $appZone = (string) config('app.timezone');

        return [
            $start->setTimezone($appZone),
            $start->addDay()->subSecond()->setTimezone($appZone),
        ];
    }

    /**
     * Forget cached settings, after an outlet's clock was changed.
     */
    public function forget(Outlet|string $outlet): void
    {
        unset($this->clocks[$outlet instanceof Outlet ? $outlet->getKey() : $outlet]);
    }

    /**
     * @return array{zone: string, hour: int}
     */
    private function clock(Outlet|string $outlet): array
    {
        $id = $outlet instanceof Outlet ? (string) $outlet->getKey() : $outlet;

        if (isset($this->clocks[$id])) {
            return $this->clocks[$id];
        }

        $settings = $outlet instanceof Outlet ? $outlet->getAttributes() : [];

        // A model fresh from a factory, or loaded with a narrow select, may
        // not carry the columns; the database always does.
        if (! array_key_exists('timezone', $settings) || ! array_key_exists('day_starts_at', $settings)) {
            $settings = (array) Outlet::withTrashed()->whereKey($id)->toBase()->first(['timezone', 'day_starts_at']);
        }

        return $this->clocks[$id] = [
            'zone' => is_string($settings['timezone'] ?? null) && $settings['timezone'] !== '' ? $settings['timezone'] : 'Asia/Jakarta',
            'hour' => max(0, min(12, (int) ($settings['day_starts_at'] ?? 4))),
        ];
    }
}
