<?php

declare(strict_types=1);

namespace App\Domain\Sync\Handlers;

use App\Domain\Sales\Models\CashierShift;
use App\Domain\Sales\ShiftRegister;
use App\Domain\Sync\Contracts\SyncHandler;
use App\Domain\Sync\SyncContext;
use Illuminate\Validation\Rule;

/**
 * shift.opened and shift.closed from an offline terminal.
 *
 * Commands from one device arrive in the order they happened, so by the time a
 * close is applied every sale rung up in that shift is already on the server.
 * That is what makes the drawer count at close final.
 */
class SyncShift implements SyncHandler
{
    use Resolves;

    public function __construct(private readonly ShiftRegister $register) {}

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(['shift.opened', 'shift.closed'])],
            'client_shift_key' => ['required_without:shift_id', 'nullable', 'string', 'max:255'],
            'shift_id' => ['nullable', 'string', 'max:40'],
            'opening_cash' => ['required_if:type,shift.opened', 'integer', 'min:0'],
            'counted_cash' => ['required_if:type,shift.closed', 'integer', 'min:0'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function apply(SyncContext $context, array $payload): array
    {
        $shift = $this->shift($context, $payload);

        if ($context->operation->type === 'shift.opened') {
            // The cashier may have opened a drawer online already. The float
            // counted offline then belongs to that one, not a second shift.
            $shift ??= $this->register->current($context->outlet, $context->user);

            $shift ??= $this->register->open(
                $context->outlet,
                $context->user,
                (int) $payload['opening_cash'],
                $context->happenedAt(),
                $payload['client_shift_key'] ?? null,
            );

            return ['shift_id' => $shift->getKey()];
        }

        abort_unless($shift instanceof CashierShift, 409, __('sync.shift_missing'));

        if (! $shift->isOpen()) {
            return ['shift_id' => $shift->getKey(), 'difference' => $shift->cash_difference];
        }

        $shift = $this->register->close(
            $shift,
            (int) $payload['counted_cash'],
            $payload['note'] ?? null,
            $context->happenedAt(),
        );

        return ['shift_id' => $shift->getKey(), 'difference' => $shift->cash_difference];
    }
}
