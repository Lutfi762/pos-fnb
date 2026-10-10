<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('orders.document_title') }} {{ $order->number }}</title>
    @include('exports._styles')
</head>
<body>
    @include('exports._printbar')

    <div class="page">
        <div class="brand">{{ $order->outlet?->receipt_header ?? $order->outlet?->name }}</div>
        <h1>{{ __('orders.document_title') }} {{ $order->number }}</h1>

        <table class="meta">
            <tr><td class="label">{{ __('reports.outlet') }}</td><td>{{ $order->outlet?->name }}@if ($order->outlet?->address) &middot; {{ $order->outlet->address }}@endif</td></tr>
            <tr><td class="label">{{ __('orders.type_label') }}</td><td>{{ $order->type->label() }}@if ($order->diningTable) &middot; {{ $order->diningTable->name }}@endif</td></tr>
            <tr><td class="label">{{ __('orders.placed_at') }}</td><td>{{ $order->placed_at?->translatedFormat('d M Y H:i') }}</td></tr>
            <tr><td class="label">{{ __('orders.paid_at') }}</td><td>{{ $order->paid_at?->translatedFormat('d M Y H:i') ?? '-' }}</td></tr>
            <tr><td class="label">{{ __('orders.cashier') }}</td><td>{{ $order->cashier?->name }}</td></tr>
            <tr><td class="label">{{ __('orders.status_label') }}</td><td>{{ $order->status->label() }}</td></tr>
        </table>

        <table class="data">
            <thead>
                <tr>
                    <th>{{ __('orders.line') }}</th>
                    <th class="num">{{ __('orders.quantity') }}</th>
                    <th class="num">{{ __('orders.unit_price') }}</th>
                    <th class="num">{{ __('orders.line_total') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($order->items as $item)
                    <tr>
                        <td>
                            {{ $item->product_name }}@if ($item->variant_name) <span class="muted">({{ $item->variant_name }})</span>@endif
                            @foreach ($item->modifiers as $modifier)
                                <br><span class="muted">+ {{ $modifier->modifier_name }}</span>
                            @endforeach
                            @if ($item->status === \App\Domain\Sales\Enums\OrderItemStatus::Voided)
                                <br><span class="muted">{{ $item->status->label() }}</span>
                            @endif
                        </td>
                        <td class="num">{{ \App\Support\Quantity::format((float) $item->quantity) }}</td>
                        <td class="num">{{ \App\Support\Money::format((int) $item->unit_price) }}</td>
                        <td class="num">{{ \App\Support\Money::format((int) $item->line_total) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <table class="totals">
            <tr><td>{{ __('orders.subtotal') }}</td><td class="num">{{ \App\Support\Money::format((int) $order->subtotal) }}</td></tr>
            @if ((int) $order->discount_total > 0)
                <tr><td>{{ __('reports.discounts') }}</td><td class="num">-{{ \App\Support\Money::format((int) $order->discount_total) }}</td></tr>
            @endif
            @if ((int) $order->service_charge_total > 0)
                <tr><td>{{ __('reports.service_charge') }}</td><td class="num">{{ \App\Support\Money::format((int) $order->service_charge_total) }}</td></tr>
            @endif
            @if ((int) $order->tax_total > 0)
                <tr><td>{{ __('reports.tax') }}</td><td class="num">{{ \App\Support\Money::format((int) $order->tax_total) }}</td></tr>
            @endif
            @if ((int) $order->rounding !== 0)
                <tr><td>{{ __('orders.rounding') }}</td><td class="num">{{ \App\Support\Money::format((int) $order->rounding) }}</td></tr>
            @endif
            <tr class="grand"><td>{{ __('reports.total') }}</td><td class="num">{{ \App\Support\Money::format((int) $order->grand_total) }}</td></tr>
            @foreach ($order->payments as $payment)
                <tr><td class="muted">{{ $payment->method_name }}@if ($payment->reference) ({{ $payment->reference }})@endif</td><td class="num muted">{{ \App\Support\Money::format((int) $payment->amount) }}</td></tr>
            @endforeach
            @foreach ($order->refunds as $refund)
                <tr><td class="muted">{{ __('orders.refunds') }} {{ $refund->refunded_at?->translatedFormat('d M H:i') }}</td><td class="num muted">-{{ \App\Support\Money::format((int) $refund->amount) }}</td></tr>
            @endforeach
            @if ((int) $order->refunded_total > 0)
                <tr class="grand"><td>{{ __('orders.net_paid') }}</td><td class="num">{{ \App\Support\Money::format((int) $order->grand_total - (int) $order->refunded_total) }}</td></tr>
            @endif
        </table>

        @if ($order->outlet?->receipt_footer)
            <p class="note">{{ $order->outlet->receipt_footer }}</p>
        @endif
    </div>
</body>
</html>
