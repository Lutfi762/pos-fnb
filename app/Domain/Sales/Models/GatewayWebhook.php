<?php

declare(strict_types=1);

namespace App\Domain\Sales\Models;

use App\Models\DomainModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A webhook exactly as the gateway sent it.
 */
class GatewayWebhook extends DomainModel
{
    public const RECEIVED = 'received';

    public const PROCESSED = 'processed';

    public const IGNORED = 'ignored';

    public const REJECTED = 'rejected';

    public const FAILED = 'failed';

    protected function casts(): array
    {
        return [
            'signature_valid' => 'boolean',
            'headers' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<PaymentCharge, $this>
     */
    public function charge(): BelongsTo
    {
        return $this->belongsTo(PaymentCharge::class, 'payment_charge_id');
    }
}
