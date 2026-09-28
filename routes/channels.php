<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function (User $user, string $id): bool {
    return (string) $user->id === $id;
});

// The board for one outlet. Anyone who may work at that outlet may watch it:
// a cook, a cashier reading the pass, and the manager checking on both.
Broadcast::channel('outlet.{outletId}.kitchen', function (User $user, string $outletId): bool {
    return $user->is_active && $user->canAccessOutlet($outletId);
});

// QRIS codes paid or expired, so the till showing one can move on. Same
// people as the board: whoever may work at the outlet.
Broadcast::channel('outlet.{outletId}.payments', function (User $user, string $outletId): bool {
    return $user->is_active && $user->canAccessOutlet($outletId);
});
