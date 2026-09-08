<?php

declare(strict_types=1);

namespace App\Domain\Sales\Models;

use App\Models\DomainModel;
use Illuminate\Database\Eloquent\SoftDeletes;

class PaymentMethod extends DomainModel
{
    use SoftDeletes;

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

    /**
     * Provider fee withheld from a payment, rounded down to whole rupiah.
     */
    public function feeFor(int $amount): int
    {
        return (int) floor($amount * (float) $this->fee_percent / 100);
    }
}
