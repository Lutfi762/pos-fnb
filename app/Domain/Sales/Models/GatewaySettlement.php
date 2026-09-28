<?php

declare(strict_types=1);

namespace App\Domain\Sales\Models;

use App\Models\DomainModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of the gateway's settlement report and what it matched on our side.
 */
class GatewaySettlement extends DomainModel
{
    // Same payment, same amount.
    public const MATCHED = 'matched';

    // Same payment, different amount.
    public const AMOUNT_MISMATCH = 'amount_mismatch';

    // The gateway collected it; we have no payment for it.
    public const UNKNOWN = 'unknown';

    protected function casts(): array
    {
        return [
            'transaction_date' => 'date',
            'amount' => 'integer',
            'fee' => 'integer',
            'net_amount' => 'integer',
            'transacted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
