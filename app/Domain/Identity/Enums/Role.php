<?php

declare(strict_types=1);

namespace App\Domain\Identity\Enums;

enum Role: string
{
    case Owner = 'owner';
    case Manager = 'manager';
    case Cashier = 'cashier';
    case Kitchen = 'kitchen';
    case Warehouse = 'warehouse';

    public function label(): string
    {
        return __('roles.'.$this->value);
    }

    /**
     * Owners see every outlet; everyone else only sees what they are assigned.
     */
    public function seesAllOutlets(): bool
    {
        return $this === self::Owner;
    }

    /**
     * Roles allowed to manage the catalog, staff, and outlet settings.
     */
    public function managesOutlet(): bool
    {
        return $this === self::Owner || $this === self::Manager;
    }

    /**
     * Roles allowed into the stockroom: counts, deliveries, and purchases.
     */
    public function managesStock(): bool
    {
        return $this->managesOutlet() || $this === self::Warehouse;
    }

    /**
     * Roles that throw things away and write it down: the stockroom, plus the
     * kitchen, where most of the spilling happens.
     */
    public function logsWaste(): bool
    {
        return $this->managesStock() || $this === self::Kitchen;
    }
}
