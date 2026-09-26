<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Identity\CurrentOutlet;
use App\Domain\Sync\ApplyOperation;
use App\Domain\Sync\DeviceRegistry;
use App\Domain\Sync\SyncHandlers;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Receives one queued command from a terminal's offline outbox.
 */
class SyncOperationController
{
    public function __invoke(
        Request $request,
        CurrentOutlet $current,
        DeviceRegistry $devices,
        ApplyOperation $apply,
        SyncHandlers $handlers,
    ): JsonResponse {
        $command = $this->command($request, $handlers);
        $handler = $handlers->for($command);

        $data = $request->validate([
            'operation_key' => ['required', 'string', 'max:255'],
            'sequence' => ['required', 'integer', 'min:1'],
            'client_created_at' => ['required', 'date'],
        ] + $handler->rules());

        $outlet = $current->getOrFail();
        $user = $request->user();

        abort_unless($user instanceof User, 403);

        $outcome = $apply->handle($outlet, $user, $devices->current($outlet), $command, $data);

        return response()->json([
            ...$outcome['result'],
            'operation_key' => $outcome['operation']->operation_key,
            'status' => $outcome['operation']->status->value,
        ], $outcome['status']);
    }

    /**
     * The kind of command, named by the route for the original endpoints and
     * by the body for the general one.
     */
    private function command(Request $request, SyncHandlers $handlers): string
    {
        $fixed = $request->route('command');

        if ($fixed === 'shift') {
            $request->validate(['type' => ['required', Rule::in(['shift.opened', 'shift.closed'])]]);

            return (string) $request->input('type');
        }

        if (is_string($fixed)) {
            return $fixed;
        }

        $request->validate(['command' => ['required', Rule::in($handlers->types())]]);

        return (string) $request->input('command');
    }
}
