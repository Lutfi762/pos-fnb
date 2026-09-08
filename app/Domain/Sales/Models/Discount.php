<?php

declare(strict_types=1);

namespace App\Domain\Sales\Models;

use App\Domain\Sales\Enums\DiscountType;
use App\Models\DomainModel;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class Discount extends DomainModel
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'type' => DiscountType::class,
            'value' => 'integer',
            'min_subtotal' => 'integer',
            'max_deduction' => 'integer',
            'requires_approval' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Whether this discount may be applied to a subtotal right now.
     */
    public function isApplicableTo(int $subtotal, ?Carbon $at = null): bool
    {
        $at ??= now();

        return $this->is_active
            && $subtotal >= $this->min_subtotal
            && ($this->starts_at === null || $at->gte($this->starts_at))
            && ($this->ends_at === null || $at->lte($this->ends_at));
    }

    /**
     * Rupiah taken off a subtotal, honouring the ceiling when there is one.
     */
    public function deductionFor(int $subtotal): int
    {
        /** @var DiscountType $type */
        $type = $this->type;

        return $type->deductionFor($subtotal, $this->value, $this->max_deduction);
    }
}
