<?php

declare(strict_types=1);

namespace App\Domain\Sales;

use App\Domain\Audit\Audit;
use App\Domain\Inventory\DeductSaleStock;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Models\InventoryItem;
use App\Domain\Inventory\MoveStock;
use App\Domain\Sales\Enums\OrderItemStatus;
use App\Domain\Sales\Enums\OrderStatus;
use App\Domain\Sales\Models\Order;
use App\Domain\Sales\Models\OrderItem;
use App\Domain\Sales\Models\PaymentMethod;
use App\Domain\Sales\Models\Refund;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Gives money back on a bill that was already settled.
 *
 * The bill itself is never touched: its lines, totals, and payments stay
 * exactly as they were rung up. A refund is a new record, dated today, that
 * points at it. That is what keeps last week's report reading the same after
 * a guest brings a cold latte back this morning.
 */
class RefundOrder
{
    public function __construct(
        private readonly MoveStock $stock,
        private readonly DeductSaleStock $recipes,
        private readonly ShiftRegister $shifts,
        private readonly Audit $audit,
        private readonly QrisPayments $qris,
    ) {}

    /**
     * @param  array<string, int|float|string>  $quantities  order item id to quantity to refund
     */
    public function handle(
        Order $order,
        User $requester,
        User $approver,
        array $quantities,
        string $reason,
        bool $restock,
        PaymentMethod $method,
        ?CarbonInterface $at = null,
    ): Refund {
        abort_if(trim($reason) === '', 422, __('refunds.reason_required'));

        // The screen asks for a PIN, but the rule lives here: whoever calls
        // this, the money only goes out on a manager's or owner's say-so.
        abort_unless(
            $approver->isOwner() || ($approver->roleAt($order->outlet_id)?->managesOutlet() ?? false),
            403,
            __('refunds.approver_required'),
        );

        return DB::transaction(function () use ($order, $requester, $approver, $quantities, $reason, $restock, $method, $at): Refund {
            $order = Order::query()->with(['items.refundItems', 'outlet'])->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            abort_unless($order->status === OrderStatus::Paid, 409, __('refunds.not_paid'));

            [$lines, $netSelected, $refundsEverything] = $this->lines($order, $quantities);

            $amount = $this->amountFor($order, $netSelected, $refundsEverything);

            abort_unless($amount > 0, 422, __('refunds.nothing_left'));

            $refund = $order->refunds()->create([
                'outlet_id' => $order->outlet_id,
                'cashier_shift_id' => $this->shifts->current($order->outlet, $requester)?->getKey(),
                'payment_method_id' => $method->getKey(),
                'payment_charge_id' => null,
                'gateway_reference' => null,
                'gateway_status' => null,
                'method_name' => $method->name,
                'user_id' => $requester->getKey(),
                'approved_by' => $approver->getKey(),
                'amount' => $amount,
                'reason' => trim($reason),
                'restock' => $restock,
                'refunded_at' => $at ?? now(),
            ]);

            $this->writeItems($refund, $lines, $amount, $netSelected);

            // Back through the gateway, to the wallet it came from. If the
            // gateway says no, nothing here is kept: the cashier can pick
            // cash instead.
            if ($method->usesGateway()) {
                $this->qris->refund($refund, $order, trim($reason));
            }

            $order->update(['refunded_total' => $order->refunded_total + $amount]);

            if ($restock) {
                $this->restock($refund, $order, $lines, $requester);
            }

            $this->audit->record('order.refunded', $order, ['refunded_total' => $order->refunded_total - $amount], [
                'refund_id' => $refund->getKey(),
                'amount' => $amount,
                'restock' => $restock,
                'method' => $method->name,
                'gateway_reference' => $refund->gateway_reference,
            ], trim($reason), $requester, $approver);

            return $refund->load('items');
        });
    }

    /**
     * The money a set of lines would give back, for the screen to show
     * before the supervisor signs it off. Same arithmetic as the refund.
     *
     * @param  array<string, int|float|string>  $quantities
     */
    public function preview(Order $order, array $quantities): int
    {
        $order->loadMissing('items.refundItems');

        try {
            [, $net, $everything] = $this->lines($order, $quantities);
        } catch (HttpException) {
            return 0;
        }

        return $this->amountFor($order, $net, $everything);
    }

    /**
     * Line totals are before the bill discount, service charge, and tax. The
     * guest gets back the same share of what they actually paid; refunding
     * whatever is left gives back exactly what is left, so rounding never
     * strands a rupiah on the bill.
     */
    private function amountFor(Order $order, int $net, bool $everything): int
    {
        $amount = $everything
            ? $order->refundable()
            : (int) round($order->grand_total * $net / max(1, $order->subtotal));

        return min($amount, $order->refundable());
    }

    /**
     * What can still be refunded per line, after earlier refunds.
     */
    public function remaining(OrderItem $item): float
    {
        $item->loadMissing('refundItems');

        return max(0, round((float) $item->quantity - (float) $item->refundItems->sum('quantity'), 3));
    }

    /**
     * @param  array<string, int|float|string>  $quantities
     * @return array{0: list<array{item: OrderItem, quantity: float, net: int}>, 1: int, 2: bool}
     */
    private function lines(Order $order, array $quantities): array
    {
        $lines = [];
        $net = 0;

        foreach ($quantities as $itemId => $quantity) {
            $quantity = round((float) $quantity, 3);

            if ($quantity <= 0) {
                continue;
            }

            $item = $order->items->firstWhere('id', $itemId);

            abort_unless($item instanceof OrderItem && $item->status !== OrderItemStatus::Voided, 422, __('refunds.unknown_line'));
            abort_if($quantity > $this->remaining($item) + 0.0005, 422, __('refunds.too_many', ['item' => $item->product_name]));

            $share = (int) round($item->line_total * $quantity / max(0.001, (float) $item->quantity));

            $lines[] = ['item' => $item, 'quantity' => $quantity, 'net' => $share];
            $net += $share;
        }

        abort_if($lines === [], 422, __('refunds.nothing_selected'));

        // After this refund, is anything on the bill still unrefunded?
        $refundsEverything = $order->items
            ->reject(fn (OrderItem $item): bool => $item->status === OrderItemStatus::Voided)
            ->every(function (OrderItem $item) use ($lines): bool {
                $taken = collect($lines)->firstWhere('item.id', $item->getKey())['quantity'] ?? 0;

                return $this->remaining($item) - $taken < 0.0005;
            });

        return [$lines, $net, $refundsEverything];
    }

    /**
     * Split the refund over its lines in proportion, the last line taking the
     * remainder so the lines always add up to the refund exactly.
     *
     * @param  list<array{item: OrderItem, quantity: float, net: int}>  $lines
     */
    private function writeItems(Refund $refund, array $lines, int $amount, int $net): void
    {
        $left = $amount;

        foreach ($lines as $index => $line) {
            $share = $index === count($lines) - 1
                ? $left
                : (int) round($amount * $line['net'] / max(1, $net));

            $left -= $share;

            $refund->items()->create([
                'order_item_id' => $line['item']->getKey(),
                'quantity' => $line['quantity'],
                'amount' => $share,
            ]);
        }
    }

    /**
     * Put the ingredients of the returned dishes back, when they can be: a
     * sealed bottle can, a latte that was drunk cannot. The cashier decides.
     *
     * @param  list<array{item: OrderItem, quantity: float, net: int}>  $lines
     */
    private function restock(Refund $refund, Order $order, array $lines, User $user): void
    {
        $usage = $this->recipes->usageFor(array_values(array_map(fn (array $line): array => [
            'variant_id' => (string) $line['item']->product_variant_id,
            'quantity' => $line['quantity'],
        ], array_filter($lines, fn (array $line): bool => $line['item']->product_variant_id !== null))));

        $items = InventoryItem::query()->whereIn('id', array_keys($usage))->get()->keyBy('id');

        foreach ($usage as $itemId => $quantity) {
            $item = $items->get($itemId);

            if ($item instanceof InventoryItem && $quantity > 0) {
                $this->stock->record($order->outlet, $item, StockMovementType::Return, $quantity, $user, source: $refund, note: $order->number);
            }
        }
    }
}
