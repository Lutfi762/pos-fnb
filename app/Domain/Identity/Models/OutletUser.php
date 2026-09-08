<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Domain\Identity\Enums\Role;
use App\Models\UlidPivot;

/**
 * @property Role $role
 */
class OutletUser extends UlidPivot
{
    protected $table = 'outlet_user';

    protected function casts(): array
    {
        return [
            'role' => Role::class,
        ];
    }
}
