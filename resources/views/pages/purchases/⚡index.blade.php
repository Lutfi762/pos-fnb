<?php

use App\Domain\Identity\CurrentOutlet;
use App\Domain\Identity\Models\Outlet;
use App\Domain\Purchasing\Enums\PurchaseOrderStatus;
use App\Domain\Purchasing\Models\PurchaseOrder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts::app')] #[Title('Purchases')] class extends Component {
    #[Url(as: 'status', except: '')]
    public string $status = '';

    #[Computed]
    public function outlet(): Outlet
    {
        return app(CurrentOutlet::class)->getOrFail();
    }

    /**
     * Orders for this outlet, newest first.
     *
     * ponytail: capped at the last 100 rather than paged. A stockroom looks
     * at this week, not last year; the report screen is where history lives.
     *
     * @return Collection<int, PurchaseOrder>
     */
    #[Computed]
    public function orders(): Collection
    {
        return PurchaseOrder::query()
            ->with(['supplier', 'items'])
            ->where('outlet_id', $this->outlet->getKey())
            ->when($this->status !== '', fn ($query) => $query->where('status', $this->status))
            ->latest('created_at')
            ->limit(100)
            ->get();
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function statuses(): array
    {
        $labels = [];

        foreach (PurchaseOrderStatus::cases() as $case) {
            $labels[$case->value] = $case->label();
        }

        return $labels;
    }
}; ?>

<div class="flex w-full flex-col gap-6">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <flux:heading size="xl" class="font-display">{{ __('purchasing.title') }}</flux:heading>
            <flux:text class="mt-1 text-content-muted">
                {{ __('purchasing.subtitle', ['outlet' => $this->outlet->name]) }}
            </flux:text>
        </div>

        <flux:button variant="primary" icon="plus" :href="route('purchases.create')" wire:navigate data-test="add-order">
            {{ __('purchasing.create') }}
        </flux:button>
    </div>

    <flux:select wire:model.live="status" :label="__('purchasing.filter_status')" class="max-w-48" data-test="status-filter">
        <flux:select.option value="">{{ __('purchasing.all_statuses') }}</flux:select.option>

        @foreach ($this->statuses as $value => $label)
            <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
        @endforeach
    </flux:select>

    @if ($this->orders->isEmpty())
        <x-empty-state
            icon="truck"
            :heading="__('purchasing.empty_heading')"
            :description="__('purchasing.empty_description')"
        />
    @else
        <div class="relative overflow-x-auto rounded-xl border border-line bg-surface">
            <table class="w-full border-collapse">
                <thead>
                    <tr class="border-b border-line text-sm text-content-muted">
                        <th scope="col" class="px-4 py-3 text-start font-medium">{{ __('purchasing.number') }}</th>
                        <th scope="col" class="px-4 py-3 text-start font-medium">{{ __('purchasing.supplier') }}</th>
                        <th scope="col" class="px-4 py-3 text-end font-medium">{{ __('purchasing.total') }}</th>
                        <th scope="col" class="px-4 py-3 text-end font-medium">
                            <span class="sr-only">{{ __('purchasing.edit') }}</span>
                        </th>
                    </tr>
                </thead>

                <tbody>
                    @foreach ($this->orders as $order)
                        <tr wire:key="po-{{ $order->id }}" class="border-b border-line align-top last:border-0">
                            <td class="px-4 py-3">
                                <span class="block font-mono text-sm text-content">{{ $order->number }}</span>
                                <span class="block text-xs text-content-muted">
                                    {{ $order->created_at->translatedFormat('d M Y') }}
                                </span>
                            </td>

                            <td class="px-4 py-3">
                                <span class="block text-content">{{ $order->supplier->name }}</span>
                                <flux:badge size="sm" class="mt-1" :color="$order->status->color()">
                                    {{ $order->status->label() }}
                                </flux:badge>
                            </td>

                            <td class="px-4 py-3 text-end">
                                <x-money :amount="$order->grand_total" />
                            </td>

                            <td class="px-4 py-3 text-end">
                                <flux:button
                                    size="sm"
                                    variant="subtle"
                                    icon="arrow-right"
                                    :href="route('purchases.show', $order)"
                                    wire:navigate
                                    data-test="open-{{ $order->id }}"
                                >
                                    {{ __('purchasing.receive') }}
                                </flux:button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
