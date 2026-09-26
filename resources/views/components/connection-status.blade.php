{{--
| The connection badge. Online, offline, or sending what was queued offline,
| with the queue itself one tap away. Driven by the outbox in the browser, so
| it shows the truth for this device even when the server cannot be reached.
--}}
<div
    x-data="connectionStatus(@js(array_merge(\App\Support\Labels::group('offline'), ['commands' => \App\Support\Labels::group('sync')['command'] ?? []])))"
    {{ $attributes->class('relative') }}
    @keydown.escape.window="open = false"
    data-test="connection-status"
>
    <button
        type="button"
        class="conn-badge"
        :class="{
            'conn-online': state === 'online',
            'conn-syncing': state === 'syncing' && ! blocked,
            'conn-offline': state === 'offline',
            'conn-danger': blocked || stale,
        }"
        @click="open = ! open"
        :aria-expanded="open"
        aria-haspopup="true"
    >
        <span class="conn-dot" aria-hidden="true"></span>
        <span x-text="label">{{ __('offline.online') }}</span>
    </button>

    <div class="conn-panel" x-show="open" x-cloak x-transition.opacity @click.outside="open = false" role="dialog" aria-label="{{ __('offline.queue') }}">
        <p class="font-semibold">{{ __('offline.queue') }}</p>

        <p class="text-sm text-danger-text" x-show="stale">{{ __('offline.stale') }}</p>

        <p class="text-sm text-danger-text" x-show="blocked">
            <span x-text="labels.blocked.replace(':message', blocked?.message || '')"></span>
            <span class="block text-content-muted">{{ __('offline.blocked_hint') }}</span>
        </p>

        <p class="text-sm text-content-muted" x-show="count === 0">{{ __('offline.queue_empty') }}</p>

        <ul class="conn-list" x-show="count > 0">
            <template x-for="operation in operations.slice(0, 8)" :key="operation.operation_key">
                <li>
                    <span x-text="commandLabel(operation.command)"></span>
                    <span class="text-content-muted" x-text="time(operation.client_created_at)"></span>
                </li>
            </template>
        </ul>

        <div class="flex flex-wrap gap-2">
            <flux:button size="sm" x-show="online && count > 0" x-on:click="syncNow()">{{ __('offline.sync_now') }}</flux:button>
            <flux:button size="sm" variant="primary" :href="route('pos.offline')" x-show="! online">{{ __('offline.open_terminal') }}</flux:button>
        </div>
    </div>
</div>
