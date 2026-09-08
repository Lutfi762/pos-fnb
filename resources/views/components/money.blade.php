@props([
    'amount' => 0,
    'prefix' => true,
])

@php
    $amount = (int) $amount;
@endphp

<span
    {{ $attributes->class(['numeric tabular-nums', 'text-danger' => $amount < 0]) }}
    @if ($amount < 0) aria-label="{{ __('common.negative_amount', ['amount' => \App\Support\Money::format(abs($amount), $prefix)]) }}" @endif
>{{ \App\Support\Money::format($amount, $prefix) }}</span>
