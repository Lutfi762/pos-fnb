<?php

declare(strict_types=1);

namespace App\Domain\Kitchen\Enums;

/**
 * How a ticket is doing against its target time.
 *
 * The thresholds are relative, not absolute: a drink targeted at three
 * minutes and a steak targeted at twenty should both warn with a quarter of
 * their time left, not both at "two minutes to go".
 */
enum Urgency: string
{
    case Calm = 'calm';
    case Close = 'close';
    case Late = 'late';

    /** Warn once this share of the target time has been used up. */
    public const CLOSE_AT = 0.75;

    public static function forElapsed(int $elapsedSeconds, int $targetSeconds): self
    {
        if ($targetSeconds <= 0 || $elapsedSeconds >= $targetSeconds) {
            return self::Late;
        }

        return $elapsedSeconds >= (int) ($targetSeconds * self::CLOSE_AT)
            ? self::Close
            : self::Calm;
    }
}
