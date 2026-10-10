@props([
    'status',
])

@if ($status)
    <div {{ $attributes->merge(['class' => 'font-medium text-sm text-done-text']) }}>
        {{ $status }}
    </div>
@endif
