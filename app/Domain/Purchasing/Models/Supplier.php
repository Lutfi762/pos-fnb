<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Models;

use App\Models\DomainModel;
use Database\Factories\SupplierFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Supplier extends DomainModel
{
    /** @use HasFactory<SupplierFactory> */
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'payment_term_days' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<PurchaseOrder, $this>
     */
    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    protected static function newFactory(): SupplierFactory
    {
        return SupplierFactory::new();
    }
}
