<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\Outlet;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $outlets = Outlet::orderBy('code')->get();
        $first = $outlets->firstOrFail();

        // Demo accounts, one per role. Password and PIN are the same for all of
        // them because this data only ever exists on a developer machine.
        $people = [
            ['name' => 'Owner Meja', 'email' => 'owner@meja.test', 'role' => Role::Owner, 'all' => true],
            ['name' => 'Manajer Kemang', 'email' => 'manajer@meja.test', 'role' => Role::Manager, 'all' => false],
            ['name' => 'Kasir Kemang', 'email' => 'kasir@meja.test', 'role' => Role::Cashier, 'all' => false],
            ['name' => 'Dapur Kemang', 'email' => 'dapur@meja.test', 'role' => Role::Kitchen, 'all' => false],
            ['name' => 'Gudang Kemang', 'email' => 'gudang@meja.test', 'role' => Role::Warehouse, 'all' => false],
        ];

        foreach ($people as $person) {
            $user = User::withTrashed()->updateOrCreate(
                ['email' => $person['email']],
                [
                    'name' => $person['name'],
                    'password' => Hash::make('password'),
                    'pin_hash' => Hash::make('123456'),
                    'email_verified_at' => now(),
                    'is_active' => true,
                    'last_outlet_id' => $first->id,
                    'deleted_at' => null,
                ],
            );

            $assigned = $person['all'] ? $outlets : collect([$first]);

            $user->outlets()->sync(
                $assigned->mapWithKeys(fn (Outlet $outlet): array => [
                    $outlet->id => ['role' => $person['role']->value],
                ])->all(),
            );
        }
    }
}
