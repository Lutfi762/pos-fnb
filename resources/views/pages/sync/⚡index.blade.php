<?php

use App\Domain\Identity\CurrentOutlet;
use App\Domain\Identity\Models\Outlet;
use App\Domain\Sync\ApplyOperation;
use App\Domain\Sync\Enums\SyncStatus;
use App\Domain\Sync\Models\Device;
use App\Domain\Sync\Models\SyncOperation;
use App\Domain\Sync\ResolveOperation;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::app')] #[Title('Sinkronisasi')] class extends Component {
    /** The command a manager is writing a reason for, or null. */
    public ?string $resolving = null;

    public string $reason = '';

    public ?string $renaming = null;

    public string $deviceName = '';

    #[Computed]
    public function outlet(): Outlet
    {
        return app(CurrentOutlet::class)->getOrFail();
    }

    /**
     * @return Collection<int, Device>
     */
    #[Computed]
    public function devices(): Collection
    {
        return Device::query()
            ->where('outlet_id', $this->outlet->getKey())
            ->withCount([
                'operations as waiting_count' => fn ($query) => $query->whereIn('status', [SyncStatus::Failed->value, SyncStatus::Conflict->value]),
                'operations as today_count' => fn ($query) => $query->where('status', SyncStatus::Applied->value)->where('processed_at', '>=', now()->startOfDay()),
            ])
            ->orderByDesc('last_seen_at')
            ->get();
    }

    /**
     * Commands that stopped a device, plus applied ones that left a warning
     * nobody has signed off yet.
     *
     * @return Collection<int, SyncOperation>
     */
    #[Computed]
    public function attention(): Collection
    {
        return SyncOperation::query()
            ->with(['device', 'user'])
            ->where('outlet_id', $this->outlet->getKey())
            ->where(fn ($query) => $query
                ->whereIn('status', [SyncStatus::Failed->value, SyncStatus::Conflict->value])
                ->orWhere(fn ($query) => $query
                    ->where('status', SyncStatus::Applied->value)
                    ->whereNotNull('warning')
                    ->whereNull('resolved_at')))
            ->orderBy('device_id')
            ->orderBy('sequence')
            ->get();
    }

    /**
     * @return Collection<int, SyncOperation>
     */
    #[Computed]
    public function recent(): Collection
    {
        return SyncOperation::query()
            ->with(['device', 'user', 'resolver'])
            ->where('outlet_id', $this->outlet->getKey())
            ->whereIn('status', [SyncStatus::Applied->value, SyncStatus::Dismissed->value])
            ->latest('processed_at')
            ->limit(25)
            ->get();
    }

    public function retry(string $id): void
    {
        $outcome = app(ApplyOperation::class)->retry($this->find($id));

        unset($this->attention, $this->recent, $this->devices);

        $outcome['status'] === 200
            ? Flux::toast(variant: 'success', text: __('sync.retried'))
            : Flux::toast(variant: 'danger', text: $outcome['result']['message'] ?? __('sync.refused'));
    }

    public function startResolving(string $id): void
    {
        $this->resolving = $id;
        $this->reset('reason');
        $this->resetErrorBag();
    }

    public function cancelResolving(): void
    {
        $this->reset('resolving', 'reason');
    }

    /**
     * Set a stuck command aside, or sign off an applied command's warning.
     */
    public function resolve(): void
    {
        $this->validate(['reason' => ['required', 'string', 'max:500']], attributes: ['reason' => __('sync.reason')]);

        $operation = $this->find((string) $this->resolving);
        $action = app(ResolveOperation::class);

        $operation->status === SyncStatus::Applied
            ? $action->acknowledge($operation, auth()->user(), $this->reason)
            : $action->dismiss($operation, auth()->user(), $this->reason);

        $this->reset('resolving', 'reason');
        unset($this->attention, $this->recent, $this->devices);

        Flux::toast(variant: 'success', text: __('sync.resolved'));
    }

    public function startRenaming(string $id): void
    {
        $this->renaming = $id;
        $this->deviceName = (string) $this->devices->firstWhere('id', $id)?->name;
    }

    public function rename(): void
    {
        $this->validate(['deviceName' => ['required', 'string', 'max:60']], attributes: ['deviceName' => __('sync.device_name')]);

        Device::query()
            ->where('outlet_id', $this->outlet->getKey())
            ->whereKey($this->renaming)
            ->update(['name' => trim($this->deviceName)]);

        $this->reset('renaming', 'deviceName');
        unset($this->devices);
    }

    private function find(string $id): SyncOperation
    {
        return SyncOperation::query()->where('outlet_id', $this->outlet->getKey())->findOrFail($id);
    }

    public function label(string $type): string
    {
        return __('sync.command.'.str_replace('.', '_', $type));
    }
}; ?>

<div class="flex w-full flex-col gap-6">
    <div>
        <flux:heading size="xl" class="font-display">{{ __('sync.title') }}</flux:heading>
        <flux:text class="mt-1 text-content-muted">{{ __('sync.subtitle', ['outlet' => $this->outlet->name]) }}</flux:text>
    </div>

    {{-- Terminals. The one question here is "has every till reported in?". --}}
    <section class="report-panel" aria-labelledby="sync-devices">
        <flux:heading id="sync-devices" size="lg">{{ __('sync.devices') }}</flux:heading>

        @if ($this->devices->isEmpty())
            <flux:text class="text-content-muted">{{ __('sync.no_devices') }}</flux:text>
        @else
            <div class="overflow-x-auto">
                <table class="report-table">
                    <thead>
                        <tr>
                            <th scope="col" class="text-start">{{ __('sync.device_label') }}</th>
                            <th scope="col">{{ __('sync.last_seen') }}</th>
                            <th scope="col">{{ __('sync.last_synced') }}</th>
                            <th scope="col">{{ __('sync.today') }}</th>
                            <th scope="col">{{ __('sync.waiting') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->devices as $device)
                            <tr wire:key="d-{{ $device->id }}">
                                <th scope="row" class="text-start font-normal">
                                    @if ($renaming === $device->id)
                                        <form wire:submit="rename" class="flex items-center gap-2">
                                            <flux:input size="sm" wire:model="deviceName" :label="__('sync.device_name')" label:class="sr-only" autofocus />
                                            <flux:button size="sm" type="submit" variant="primary">{{ __('sync.save') }}</flux:button>
                                        </form>
                                    @else
                                        <button type="button" class="min-h-11 text-start underline-offset-4 hover:underline" wire:click="startRenaming('{{ $device->id }}')">
                                            {{ $device->name }}
                                        </button>
                                        <span class="block font-mono text-xs text-content-muted">{{ strtoupper(substr($device->id, -4)) }}</span>
                                    @endif
                                </th>
                                <td>{{ $device->last_seen_at?->diffForHumans() ?? '-' }}</td>
                                <td>
                                    @php($stale = $device->last_synced_at !== null && $device->last_synced_at->lt(now()->subHours(2)))
                                    <span @class(['text-pending-text font-medium' => $stale])>{{ $device->last_synced_at?->diffForHumans() ?? '-' }}</span>
                                </td>
                                <td class="numeric">{{ $device->today_count }}</td>
                                <td class="numeric">
                                    @if ($device->waiting_count > 0)
                                        <flux:badge color="amber" size="sm">{{ $device->waiting_count }}</flux:badge>
                                    @else
                                        0
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    {{-- What needs a person. Ordered by device and sequence: the top one per
         device is the one holding the rest back. --}}
    <section class="report-panel" aria-labelledby="sync-attention">
        <flux:heading id="sync-attention" size="lg">{{ __('sync.attention') }}</flux:heading>

        @if ($this->attention->isEmpty())
            <x-empty-state icon="check-circle" :heading="__('sync.all_clear')" :description="__('sync.all_clear_description')" />
        @else
            <ul class="flex flex-col gap-3" data-test="sync-attention">
                @foreach ($this->attention as $operation)
                    @php($stuck = $operation->status->needsAttention())
                    <li wire:key="op-{{ $operation->id }}" class="rounded-xl border border-line bg-surface p-4">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <flux:badge size="sm" :color="$stuck ? 'red' : 'amber'">
                                        {{ $stuck ? $operation->status->label() : __('sync.warning') }}
                                    </flux:badge>
                                    <span class="font-medium">{{ $this->label($operation->type) }}</span>
                                    <span class="font-mono text-xs text-content-muted">#{{ $operation->sequence }}</span>
                                </div>
                                <p class="mt-1 text-sm text-content-muted">
                                    {{ $operation->device?->name }} &middot; {{ $operation->user?->name }}
                                    &middot; {{ $operation->client_created_at->translatedFormat('d M H:i') }}
                                    @if ($operation->attempts > 1)
                                        &middot; {{ __('sync.attempts', ['count' => $operation->attempts]) }}
                                    @endif
                                </p>
                                <p class="mt-2">{{ $stuck ? $operation->error : $operation->warning }}</p>
                            </div>

                            <div class="flex flex-wrap gap-2">
                                @if ($stuck)
                                    <flux:button size="sm" wire:click="retry('{{ $operation->id }}')" data-test="retry">{{ __('sync.retry') }}</flux:button>
                                    <flux:button size="sm" variant="danger" wire:click="startResolving('{{ $operation->id }}')" data-test="dismiss">{{ __('sync.dismiss') }}</flux:button>
                                @else
                                    <flux:button size="sm" wire:click="startResolving('{{ $operation->id }}')" data-test="acknowledge">{{ __('sync.acknowledge') }}</flux:button>
                                @endif
                            </div>
                        </div>

                        @if ($resolving === $operation->id)
                            <form wire:submit="resolve" class="mt-3 flex flex-col gap-2">
                                <flux:textarea
                                    wire:model="reason"
                                    rows="2"
                                    :label="__('sync.reason')"
                                    :placeholder="$stuck ? __('sync.dismiss_placeholder') : __('sync.acknowledge_placeholder')"
                                    data-test="resolve-reason"
                                />
                                <div class="flex gap-2">
                                    <flux:button size="sm" type="submit" variant="primary" data-test="resolve-confirm">{{ __('sync.confirm') }}</flux:button>
                                    <flux:button size="sm" variant="subtle" wire:click="cancelResolving">{{ __('sync.cancel') }}</flux:button>
                                </div>
                            </form>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <section class="report-panel" aria-labelledby="sync-recent">
        <flux:heading id="sync-recent" size="lg">{{ __('sync.recent') }}</flux:heading>

        @if ($this->recent->isEmpty())
            <flux:text class="text-content-muted">{{ __('sync.no_recent') }}</flux:text>
        @else
            <div class="overflow-x-auto">
                <table class="report-table">
                    <thead>
                        <tr>
                            <th scope="col" class="text-start">{{ __('sync.command_label') }}</th>
                            <th scope="col" class="text-start">{{ __('sync.device_label') }}</th>
                            <th scope="col">{{ __('sync.happened') }}</th>
                            <th scope="col">{{ __('sync.received') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->recent as $operation)
                            <tr wire:key="r-{{ $operation->id }}">
                                <th scope="row" class="text-start font-normal">
                                    {{ $this->label($operation->type) }}
                                    @if ($operation->status === \App\Domain\Sync\Enums\SyncStatus::Dismissed)
                                        <flux:badge size="sm" color="zinc">{{ $operation->status->label() }}</flux:badge>
                                    @endif
                                </th>
                                <td class="text-start">{{ $operation->device?->name ?? '-' }}</td>
                                <td>{{ $operation->client_created_at->translatedFormat('d M H:i') }}</td>
                                <td>{{ $operation->processed_at?->translatedFormat('d M H:i') ?? '-' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</div>
