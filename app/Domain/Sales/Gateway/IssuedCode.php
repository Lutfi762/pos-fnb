<?php

declare(strict_types=1);

namespace App\Domain\Sales\Gateway;

use Carbon\CarbonInterface;

final readonly class IssuedCode
{
    public function __construct(
        public string $providerChargeId,
        public string $qrString,
        public CarbonInterface $expiresAt,
    ) {}
}
