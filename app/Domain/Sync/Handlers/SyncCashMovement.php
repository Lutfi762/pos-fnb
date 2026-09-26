<?php

declare(strict_types=1);

namespace App\Domain\Sync\Handlers;

use App\Domain\Sales\Models\CashierShift;
use App\Domain\Sales\Models\CashMovement;
use App\Domain\Sales\ShiftRegister;
use App\Domain\Sync\Contracts\SyncHandler;
use App\Domain\Sync\SyncContext;
use Illuminate\Validation\Rule;

/**
 * cash.moved: money into or out of the drawer, recorded offline.
 */
class SyncCashMovement implements SyncHandler
{
    use Resolves;

    public function __construct(private readonly ShiftRegister $register) {}

    public function rules(): array
    {
        return [
            'client_shift_key' => ['required_without:shift_id', 'nullable', 'string', 'max:255'],
            'shift_id' => ['nullable', 'string', 'max:40'],
            'direction' => ['required', Rule::in([CashMovement::IN, CashMovement::OUT])],
            'amount' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:255'],
        ];
    }

    public function apply(SyncContext $context, array $payload): array
    {
        $shift = $this->shift($context, $payload);

        abort_unless($shift instanceof CashierShift, 409, __('sync.shift_missing'));

        $movement = $this->register->moveCash(
            $shift,
            $context->user,
            $payload['direction'],
            (int) $payload['amount'],
            $payload['reason'],
            $context->happenedAt(),
            $context->operation->operation_key,
        );

        return ['cash_movement_id' => $movement->getKey()];
    }
}
