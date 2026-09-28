<?php

declare(strict_types=1);

namespace App\Domain\Sales\Gateway;

use Carbon\CarbonInterface;

/**
 * The gateway's answer to "has this code been paid?".
 */
final readonly class ChargeState
{
    public function __construct(
        public bool $paid,
        public int $amount = 0,
        public ?string $providerPaymentId = null,
        public ?CarbonInterface $paidAt = null,
        public bool $closed = false,
    ) {}

    public static function unpaid(bool $closed = false): self
    {
        return new self(false, closed: $closed);
    }
}
