<?php

declare(strict_types=1);

namespace App\Domain\Sync\Handlers;

use App\Domain\Sales\Models\PaymentMethod;
use App\Domain\Sales\TakePayment;
use App\Domain\Sync\Contracts\SyncHandler;
use App\Domain\Sync\SyncContext;
use App\Support\Money;

/**
 * payment.recorded: cash taken while the terminal was offline.
 *
 * Only cash. A card or QRIS payment needs the bank to say yes, and nobody can
 * ask the bank without a connection.
 */
class SyncCashPayment implements SyncHandler
{
    use Resolves;

    public function __construct(private readonly TakePayment $payments) {}

    public function rules(): array
    {
        return [
            'order_id' => ['required_without:client_order_key', 'nullable', 'string', 'max:255'],
            'client_order_key' => ['required_without:order_id', 'nullable', 'string', 'max:255'],
            'payment_method_id' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'integer', 'min:1'],
            'tendered' => ['required', 'integer', 'min:1'],
        ];
    }

    public function apply(SyncContext $context, array $payload): array
    {
        $order = $this->order($context, $payload);

        $method = PaymentMethod::query()->where('is_active', true)->whereKey($payload['payment_method_id'])->firstOrFail();

        abort_unless($method->type === 'cash', 422, __('sync.cash_only'));

        $payment = $this->payments->handle(
            $order,
            $method,
            $context->user,
            (int) $payload['amount'],
            (int) $payload['tendered'],
            paidAt: $context->happenedAt(),
        );

        $order->refresh();

        // The device totalled the bill from the menu it had cached. When the
        // server's menu disagrees, the sale still stands: the guest has paid.
        // The difference is left for a manager to settle on the sync screen.
        $warning = $order->outstanding() > 0
            ? __('sync.short_paid', ['amount' => Money::format($order->outstanding()), 'number' => $order->number])
            : null;

        return [
            'payment_id' => $payment->getKey(),
            'order_id' => $order->getKey(),
            'order_status' => $order->status->value,
            'warning' => $warning,
        ];
    }
}
