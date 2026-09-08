@props([
    'icon' => 'inbox',
    'heading' => '',
    'description' => null,
])

<div {{ $attributes->class('motion-rise flex flex-col items-center justify-center rounded-xl border border-dashed border-line px-6 py-12 text-center') }}>
    <flux:icon :name="$icon" class="size-8 text-content-muted" aria-hidden="true" />

    <flux:heading size="lg" class="mt-4">{{ $heading }}</flux:heading>

    @if ($description)
        <flux:text class="mt-1 max-w-sm text-content-muted">{{ $description }}</flux:text>
    @endif

    @isset($actions)
        <div class="mt-5 flex flex-wrap items-center justify-center gap-2">
            {{ $actions }}
        </div>
    @endisset
</div>
