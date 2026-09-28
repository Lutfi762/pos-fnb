<?php

declare(strict_types=1);

namespace App\Domain\Sales\Gateway;

use Carbon\CarbonInterface;

/**
 * A webhook, read and checked, in the till's own words.
 */
final readonly class WebhookEvent
{
    public const PAID = 'paid';

    public const EXPIRED = 'expired';

    public const OTHER = 'other';

    public function __construct(
        public string $eventId,
        public string $type,
        public string $kind,
        public ?string $reference = null,
        public ?string $providerChargeId = null,
        public ?string $providerPaymentId = null,
        public int $amount = 0,
        public ?CarbonInterface $paidAt = null,
    ) {}
}
