<?php

declare(strict_types=1);

namespace App\Domain\Sales\Gateway;

use Carbon\CarbonInterface;

final readonly class SettlementLine
{
    public function __construct(
        public string $providerTransactionId,
        public ?string $reference,
        public int $amount,
        public int $fee,
        public int $netAmount,
        public ?string $settlementStatus,
        public CarbonInterface $transactedAt,
    ) {}
}
