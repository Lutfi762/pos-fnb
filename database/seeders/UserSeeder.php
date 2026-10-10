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

        // Demo accounts, one per role, all with the password "password".
        // PINs differ: a supervisor is recognised by PIN alone, so two people
        // sharing one could not be told apart (see Supervisors). The README
        // lists them.
        $people = [
            ['name' => 'Owner Meja', 'email' => 'owner@meja.test', 'role' => Role::Owner, 'all' => true, 'pin' => '246810'],
            ['name' => 'Manajer Kemang', 'email' => 'manajer@meja.test', 'role' => Role::Manager, 'all' => false, 'pin' => '135790'],
            ['name' => 'Kasir Kemang', 'email' => 'kasir@meja.test', 'role' => Role::Cashier, 'all' => false, 'pin' => '102030'],
            ['name' => 'Dapur Kemang', 'email' => 'dapur@meja.test', 'role' => Role::Kitchen, 'all' => false, 'pin' => '112233'],
            ['name' => 'Gudang Kemang', 'email' => 'gudang@meja.test', 'role' => Role::Warehouse, 'all' => false, 'pin' => '445566'],
        ];

        foreach ($people as $person) {
            $user = User::withTrashed()->updateOrCreate(
                ['email' => $person['email']],
                [
                    'name' => $person['name'],
                    'password' => Hash::make('password'),
                    'pin_hash' => Hash::make($person['pin']),
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
