<?php

declare(strict_types=1);

namespace App\Domain\Sales;

use App\Domain\Identity\Models\Outlet;
use App\Domain\Sales\Enums\OrderStatus;
use App\Domain\Sales\Models\CashierShift;
use App\Domain\Sales\Models\CashMovement;
use App\Domain\Sales\Models\Order;
use App\Domain\Sales\Models\Payment;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The cash drawer over one cashier's stretch of the day.
 *
 * The point of a shift is the count at the end: what the drawer should hold
 * against what it actually holds. Everything here exists to make that one
 * subtraction honest.
 */
class ShiftRegister
{
    /**
     * The shift this cashier currently has open at this outlet, if any.
     */
    public function current(Outlet $outlet, User $cashier): ?CashierShift
    {
        return CashierShift::query()
            ->where('outlet_id', $outlet->getKey())
            ->where('user_id', $cashier->getKey())
            ->whereNull('closed_at')
            ->latest('opened_at')
            ->first();
    }

    /**
     * Start a shift with the float counted into the drawer.
     *
     * Opening twice is not an error: the drawer is already open, so the
     * existing shift is handed back rather than a second one started.
     */
    public function open(Outlet $outlet, User $cashier, int $openingCash, ?CarbonInterface $at = null, ?string $clientKey = null): CashierShift
    {
        return $this->current($outlet, $cashier) ?? CashierShift::create([
            'outlet_id' => $outlet->getKey(),
            'user_id' => $cashier->getKey(),
            'client_shift_key' => $clientKey,
            'opening_cash' => max(0, $openingCash),
            'opened_at' => $at ?? now(),
        ]);
    }

    /**
     * Count the drawer down and close the shift.
     *
     * The expected amount is frozen onto the row, so the variance still reads
     * the same next month even if an order is voided in the meantime.
     */
    public function close(CashierShift $shift, int $countedCash, ?string $note = null, ?CarbonInterface $at = null): CashierShift
    {
        abort_unless($shift->isOpen(), 409, __('shifts.already_closed'));

        $expected = $this->expectedCash($shift);

        return tap($shift)->update([
            'closing_cash' => $countedCash,
            'expected_cash' => $expected,
            'cash_difference' => $countedCash - $expected,
            'note' => $note,
            'closed_at' => $at ?? now(),
        ]);
    }

    /**
     * Put cash into the drawer, or take it out, outside of a sale.
     *
     * A reason is required every time: at the end of the night this is the
     * only line that explains why the drawer is Rp18.000 lighter.
     */
    public function moveCash(
        CashierShift $shift,
        User $user,
        string $direction,
        int $amount,
        string $reason,
        ?CarbonInterface $at = null,
        ?string $clientKey = null,
    ): CashMovement {
        abort_unless($shift->isOpen(), 409, __('shifts.already_closed'));
        abort_unless(in_array($direction, [CashMovement::IN, CashMovement::OUT], true), 422);
        abort_unless($amount > 0, 422, __('shifts.amount_required'));
        abort_if(trim($reason) === '', 422, __('shifts.reason_required'));

        if ($clientKey !== null) {
            $existing = CashMovement::query()->where('client_key', $clientKey)->first();

            if ($existing instanceof CashMovement) {
                return $existing;
            }
        }

        return $shift->cashMovements()->create([
            'outlet_id' => $shift->outlet_id,
            'user_id' => $user->getKey(),
            'client_key' => $clientKey,
            'direction' => $direction,
            'amount' => $amount,
            'reason' => trim($reason),
            'moved_at' => $at ?? now(),
        ]);
    }

    /**
     * Cash put in minus cash taken out, for the drawer count.
     */
    public function cashMoved(CashierShift $shift): int
    {
        $in = (int) $shift->cashMovements()->where('direction', CashMovement::IN)->sum('amount');
        $out = (int) $shift->cashMovements()->where('direction', CashMovement::OUT)->sum('amount');

        return $in - $out;
    }

    /**
     * What the drawer should hold: the float plus the cash actually taken.
     */
    public function expectedCash(CashierShift $shift): int
    {
        return $shift->opening_cash + $this->cashTaken($shift) + $this->cashMoved($shift);
    }

    /**
     * Cash tendered during the shift, net of the change handed back.
     */
    public function cashTaken(CashierShift $shift): int
    {
        return (int) $this->payments($shift)
            ->whereHas('method', fn ($query) => $query->where('type', 'cash'))
            ->sum('amount');
    }

    /**
     * The numbers a cashier reads off the screen before counting the drawer.
     *
     * @return array{orders: int, gross: int, methods: Collection<int, object{name: string, total: int}>, cash_in: int, cash_out: int}
     */
    public function summary(CashierShift $shift): array
    {
        $orders = Order::query()
            ->where('cashier_shift_id', $shift->getKey())
            ->where('status', OrderStatus::Paid);

        /** @var Collection<int, object{name: string, total: int}> $methods */
        $methods = $this->payments($shift)
            ->selectRaw('method_name as name, sum(amount) as total')
            ->groupBy('method_name')
            ->orderBy('method_name')
            ->get()
            ->map(fn (Payment $row): object => (object) [
                'name' => (string) $row->getAttribute('name'),
                'total' => (int) $row->getAttribute('total'),
            ]);

        return [
            'orders' => (clone $orders)->count(),
            'gross' => (int) (clone $orders)->sum('grand_total'),
            'methods' => $methods,
            'cash_in' => (int) $shift->cashMovements()->where('direction', CashMovement::IN)->sum('amount'),
            'cash_out' => (int) $shift->cashMovements()->where('direction', CashMovement::OUT)->sum('amount'),
        ];
    }

    /**
     * Captured payments belonging to this shift's orders.
     *
     * @return Builder<Payment>
     */
    private function payments(CashierShift $shift): Builder
    {
        return Payment::query()
            ->whereIn('order_id', $shift->orders()->select('id'))
            ->where('status', TakePayment::STATUS_CAPTURED);
    }
}
