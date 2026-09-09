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
}
