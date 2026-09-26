<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Identity\CurrentOutlet;
use App\Domain\Sync\DeviceRegistry;
use App\Domain\Sync\OfflineSnapshot;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The pieces of the till that have to work without the server.
 */
class OfflineController
{
    /**
     * The offline till. A plain page, not a Livewire one: it is cached by the
     * service worker and has to run entirely from what is in the browser.
     */
    public function terminal(): View
    {
        return view('pages.pos.offline');
    }

    /**
     * The menu, tables, and settings the offline till rings up with.
     */
    public function snapshot(Request $request, CurrentOutlet $current, DeviceRegistry $devices, OfflineSnapshot $snapshot): JsonResponse
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);

        $outlet = $current->getOrFail();

        return response()->json($snapshot->for($outlet, $user, $devices->current($outlet)))
            ->header('Cache-Control', 'no-store');
    }

    /**
     * A fresh CSRF token and who is signed in, asked for right before an
     * outbox flush. The token in a cached page may be hours old.
     */
    public function session(Request $request, CurrentOutlet $current): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'csrf' => csrf_token(),
            'user_id' => $user instanceof User ? $user->getKey() : null,
            'outlet_id' => $current->id(),
            'server_time' => now()->toIso8601String(),
        ])->header('Cache-Control', 'no-store');
    }
}
