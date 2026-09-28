<?php

declare(strict_types=1);

namespace App\Domain\Sales\Models;

use App\Models\DomainModel;
use Illuminate\Database\Eloquent\SoftDeletes;

class PaymentMethod extends DomainModel
{
    use SoftDeletes;

    // The cashier keys a reference by hand: cards, transfers.
    public const MANUAL = 'manual';

    // The server asks the gateway for a QRIS code and waits for it to be paid.
    public const GATEWAY_QRIS = 'gateway_qris';

    // The printed QRIS standee: no gateway, a manager checks it later.
    public const STATIC_QRIS = 'static_qris';

    protected function casts(): array
    {
        return [
            'opens_cash_drawer' => 'boolean',
            'needs_reference' => 'boolean',
            'fee_percent' => 'decimal:2',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function usesGateway(): bool
    {
        return $this->mode === self::GATEWAY_QRIS;
    }

    public function isStaticQris(): bool
    {
        return $this->mode === self::STATIC_QRIS;
    }

    /**
     * Provider fee withheld from a payment, rounded down to whole rupiah.
     */
    public function feeFor(int $amount): int
    {
        return (int) floor($amount * (float) $this->fee_percent / 100);
    }
}
