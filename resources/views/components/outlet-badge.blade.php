@php
    $current = app(\App\Domain\Identity\CurrentOutlet::class);
    $outlet = $current->get();
    $role = $outlet ? $current->roleFor(auth()->user()) : null;
@endphp

@if ($outlet)
    <div {{ $attributes->class('flex items-center justify-between gap-2 rounded-lg bg-surface-sunken px-3 py-2') }}>
        <span class="min-w-0">
            <span class="block truncate text-sm font-medium text-content">{{ $outlet->name }}</span>
            @if ($role)
                <span class="block truncate text-xs text-content-muted">{{ $role->label() }}</span>
            @endif
        </span>

        <form method="POST" action="{{ route('outlet.leave') }}">
            @csrf
            <flux:button
                type="submit"
                size="xs"
                variant="subtle"
                icon="arrows-right-left"
                :tooltip="__('outlet.switch')"
                data-test="switch-outlet-button"
            />
        </form>
    </div>
@endif
