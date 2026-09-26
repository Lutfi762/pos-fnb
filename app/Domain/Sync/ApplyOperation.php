<?php

declare(strict_types=1);

namespace App\Domain\Sync;

use App\Domain\Identity\Models\Outlet;
use App\Domain\Sync\Contracts\SyncHandler;
use App\Domain\Sync\Enums\SyncStatus;
use App\Domain\Sync\Models\Device;
use App\Domain\Sync\Models\SyncOperation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Applies one command from a terminal's offline queue, exactly once.
 *
 * The envelope is the same for every kind of command: a key minted on the
 * device, a per-device sequence number, and the time it happened there. The
 * command itself is handed to the handler for its type.
 *
 * A command that fails is kept, with the reason, rather than thrown away:
 * it holds up the commands queued behind it on the same device until it is
 * retried or a manager sets it aside on the sync screen.
 */
class ApplyOperation
{
    public function __construct(
        private readonly SequencedOperation $sequence,
        private readonly SyncHandlers $handlers,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{operation: SyncOperation, result: array<string, mixed>, status: int}
     */
    public function handle(Outlet $outlet, User $user, Device $device, string $type, array $payload): array
    {
        $handler = $this->handlers->for($type);

        return DB::transaction(function () use ($outlet, $user, $device, $type, $payload, $handler): array {
            $this->sequence->assertCanApply($device, $payload['operation_key'], (int) $payload['sequence']);

            $operation = SyncOperation::query()->firstOrCreate(
                ['operation_key' => $payload['operation_key']],
                [
                    'device_id' => $device->getKey(),
                    'outlet_id' => $outlet->getKey(),
                    'user_id' => $user->getKey(),
                    'type' => $type,
                    'payload' => $payload,
                    'sequence' => $payload['sequence'],
                    // Stored in the app's timezone: a device sends UTC, and a
                    // timestamp column keeps wall time, not the offset.
                    'client_created_at' => CarbonImmutable::parse($payload['client_created_at'])->setTimezone(config('app.timezone')),
                ],
            );

            // A key belongs to the outlet it was minted in. The same command
            // arriving from a terminal whose cookie was reset is still the same
            // command, so the device is not part of this check.
            abort_unless($operation->outlet_id === $outlet->getKey(), 403);

            if ($operation->status === SyncStatus::Applied) {
                return ['operation' => $operation, 'result' => $operation->result ?? [], 'status' => 200];
            }

            if ($operation->status === SyncStatus::Dismissed) {
                return ['operation' => $operation, 'result' => ['dismissed' => true], 'status' => 200];
            }

            return $this->run($operation, $handler, new SyncContext($outlet, $user, $device, $operation), $payload);
        });
    }

    /**
     * Run a stored command again, from the sync screen, as the person who
     * queued it on the device.
     *
     * @return array{operation: SyncOperation, result: array<string, mixed>, status: int}
     */
    public function retry(SyncOperation $operation): array
    {
        abort_unless($operation->status->needsAttention(), 409, __('sync.not_retryable'));

        $operation->loadMissing(['outlet', 'device', 'user']);

        abort_unless($operation->outlet instanceof Outlet && $operation->device instanceof Device && $operation->user instanceof User, 409, __('sync.not_retryable'));

        return DB::transaction(fn (): array => $this->run(
            $operation,
            $this->handlers->for($operation->type),
            new SyncContext($operation->outlet, $operation->user, $operation->device, $operation),
            $operation->payload,
        ));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{operation: SyncOperation, result: array<string, mixed>, status: int}
     */
    private function run(SyncOperation $operation, SyncHandler $handler, SyncContext $context, array $payload): array
    {
        try {
            // A savepoint of its own: when the command fails, everything it
            // wrote is undone, but the failure itself is still recorded.
            $result = DB::transaction(fn (): array => $handler->apply($context, $payload));
        } catch (SyncConflict $conflict) {
            return $this->fail($operation, SyncStatus::Conflict, $conflict->getMessage(), 409, ['conflict' => true] + $conflict->context);
        } catch (ModelNotFoundException) {
            return $this->fail($operation, SyncStatus::Failed, __('sync.missing_record'), 422);
        } catch (ValidationException $invalid) {
            return $this->fail($operation, SyncStatus::Failed, $invalid->getMessage(), 422);
        } catch (HttpExceptionInterface $refused) {
            if (! in_array($refused->getStatusCode(), [404, 409, 422], true)) {
                throw $refused;
            }

            $message = $refused->getMessage() !== '' ? $refused->getMessage() : __('sync.refused');

            return $this->fail($operation, SyncStatus::Failed, $message, $refused->getStatusCode() === 409 ? 409 : 422);
        }

        $warning = $result['warning'] ?? null;

        $operation->update([
            'status' => SyncStatus::Applied,
            'result' => $result,
            'warning' => is_string($warning) ? $warning : null,
            'attempts' => $operation->attempts + 1,
            'error' => null,
            'processed_at' => now(),
        ]);

        $context->device->update(['last_synced_at' => now()]);

        return ['operation' => $operation, 'result' => $result, 'status' => 200];
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array{operation: SyncOperation, result: array<string, mixed>, status: int}
     */
    private function fail(SyncOperation $operation, SyncStatus $status, string $message, int $httpStatus, array $extra = []): array
    {
        $operation->update([
            'status' => $status,
            'attempts' => $operation->attempts + 1,
            'error' => $message,
            'processed_at' => now(),
        ]);

        return ['operation' => $operation, 'result' => ['message' => $message] + $extra, 'status' => $httpStatus];
    }
}
