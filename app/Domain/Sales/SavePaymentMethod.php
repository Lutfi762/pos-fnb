<?php

declare(strict_types=1);

namespace App\Domain\Sales;

use App\Domain\Audit\Audit;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\Outlet;
use App\Domain\Identity\SaveStaff;
use App\Domain\Sales\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * The ways a guest can pay, shared by every outlet. Owner only: a fee or a
 * switched-off method changes what every till accepts and what lands in the
 * bank.
 *
 * Cash is special. The offline till takes nothing else, so the last active
 * cash method can never be switched off. New methods are always keyed by
 * hand (cards, transfers, e-wallets on their own terminal); QRIS methods come
 * from the seeder and only change between dynamic and static.
 */
class SavePaymentMethod
{
    /** Types an owner may add. Cash and QRIS already exist. */
    public const NEW_TYPES = ['card', 'transfer', 'ewallet', 'other'];

    public const MAX_FEE_PERCENT = 10;

    public function __construct(private readonly Audit $audit) {}

    /**
     * @param  array{name: string, type?: string, fee_percent: string|float, needs_reference: bool, is_active: bool, mode?: string|null}  $input
     *
     * @throws ValidationException
     */
    public function handle(User $by, Outlet $outlet, array $input, ?PaymentMethod $method = null): PaymentMethod
    {
        abort_unless(SaveStaff::roleOf($by, $outlet) === Role::Owner, 403);

        $fee = round((float) $input['fee_percent'], 2);
        abort_unless($fee >= 0 && $fee <= self::MAX_FEE_PERCENT, 422, __('payment_methods.fee_invalid'));

        $before = $method?->only(['name', 'type', 'mode', 'fee_percent', 'needs_reference', 'is_active']);

        if ($method === null) {
            $type = $input['type'] ?? '';
            abort_unless(in_array($type, self::NEW_TYPES, true), 422);

            $method = new PaymentMethod([
                'type' => $type,
                'mode' => PaymentMethod::MANUAL,
                'opens_cash_drawer' => false,
                'sort_order' => (int) PaymentMethod::query()->max('sort_order') + 1,
            ]);
        }

        if ($method->type === 'cash' && ! $input['is_active'] && $method->is_active && ! $this->otherActiveCash($method)) {
            throw ValidationException::withMessages(['isActive' => __('payment_methods.cash_required')]);
        }

        $mode = $input['mode'] ?? null;

        if ($method->type === 'qris' && $mode !== null) {
            abort_unless(in_array($mode, [PaymentMethod::GATEWAY_QRIS, PaymentMethod::STATIC_QRIS], true), 422);
            $method->mode = $mode;
        }

        $method->fill([
            'name' => trim($input['name']),
            'fee_percent' => number_format($fee, 2, '.', ''),
            // A static QRIS payment is matched by its reference later; the
            // gateway sends its own. Cash never has one.
            'needs_reference' => match (true) {
                $method->type === 'cash' => false,
                $method->type === 'qris' => $method->mode === PaymentMethod::STATIC_QRIS,
                default => $input['needs_reference'],
            },
            'is_active' => $input['is_active'],
        ])->save();

        $this->audit->record(
            $before === null ? 'payment_method.created' : 'payment_method.updated',
            $method,
            $before,
            $method->only(['name', 'type', 'mode', 'fee_percent', 'needs_reference', 'is_active']),
            user: $by,
            outletId: $outlet->getKey(),
        );

        return $method;
    }

    /**
     * Swap a method with its neighbour in the list the cashier sees.
     */
    public function move(User $by, Outlet $outlet, PaymentMethod $method, int $direction): void
    {
        abort_unless(SaveStaff::roleOf($by, $outlet) === Role::Owner, 403);

        $methods = PaymentMethod::query()->orderBy('sort_order')->orderBy('name')->get()->values();
        $index = $methods->search(fn (PaymentMethod $each): bool => $each->is($method));
        $target = $index === false ? null : $methods->get($index + ($direction < 0 ? -1 : 1));

        if (! $target instanceof PaymentMethod) {
            return;
        }

        // Renumber the whole list so ties from old data cannot stick.
        $order = $methods->all();
        [$order[$index], $order[$index + ($direction < 0 ? -1 : 1)]] = [$target, $method];

        foreach (array_values($order) as $position => $each) {
            if ($each->sort_order !== $position) {
                $each->forceFill(['sort_order' => $position])->save();
            }
        }
    }

    private function otherActiveCash(PaymentMethod $method): bool
    {
        return PaymentMethod::query()
            ->where('type', 'cash')
            ->where('is_active', true)
            ->whereKeyNot($method->getKey())
            ->exists();
    }
}
