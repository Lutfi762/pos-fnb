<?php

use App\Domain\Identity\CurrentOutlet;
use App\Domain\Identity\Models\Outlet;
use App\Domain\Sales\Models\PaymentMethod;
use App\Domain\Sales\SavePaymentMethod;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::app')] #[Title('Payment methods')] class extends Component {
    #[Locked]
    public ?string $editingId = null;

    public string $name = '';

    public string $type = 'card';

    public string $mode = '';

    public string $feePercent = '0';

    public bool $needsReference = true;

    public bool $isActive = true;

    #[Computed]
    public function outlet(): Outlet
    {
        return app(CurrentOutlet::class)->getOrFail();
    }

    /**
     * @return Collection<int, PaymentMethod>
     */
    #[Computed]
    public function methods(): Collection
    {
        return PaymentMethod::query()->orderBy('sort_order')->orderBy('name')->get();
    }

    #[Computed]
    public function editing(): ?PaymentMethod
    {
        return $this->editingId === null ? null : $this->methods->firstWhere('id', $this->editingId);
    }

    public function create(): void
    {
        $this->resetForm();

        Flux::modal('method-form')->show();
    }

    public function edit(string $methodId): void
    {
        $method = $this->methods->firstWhere('id', $methodId);
        abort_unless($method instanceof PaymentMethod, 404);

        $this->resetForm();
        $this->editingId = $method->id;
        $this->name = $method->name;
        $this->type = $method->type;
        $this->mode = (string) $method->mode;
        $this->feePercent = str_replace('.', ',', rtrim(rtrim((string) $method->fee_percent, '0'), '.'));
        $this->needsReference = (bool) $method->needs_reference;
        $this->isActive = (bool) $method->is_active;

        Flux::modal('method-form')->show();
    }

    public function save(): void
    {
        $this->feePercent = str_replace(',', '.', trim($this->feePercent));
        $creating = $this->editingId === null;

        $this->validate([
            'name' => ['required', 'string', 'max:60', Rule::unique('payment_methods', 'name')->ignore($this->editingId)->whereNull('deleted_at')],
            'type' => $creating ? ['required', Rule::in(SavePaymentMethod::NEW_TYPES)] : ['nullable'],
            'mode' => ['nullable', Rule::in(['', PaymentMethod::MANUAL, PaymentMethod::GATEWAY_QRIS, PaymentMethod::STATIC_QRIS])],
            'feePercent' => ['required', 'numeric', 'min:0', 'max:'.SavePaymentMethod::MAX_FEE_PERCENT],
            'needsReference' => ['boolean'],
            'isActive' => ['boolean'],
        ], attributes: [
            'name' => __('payment_methods.name'),
            'type' => __('payment_methods.type'),
            'feePercent' => __('payment_methods.fee'),
        ]);

        $saved = app(SavePaymentMethod::class)->handle(auth()->user(), $this->outlet, [
            'name' => $this->name,
            'type' => $this->type,
            'mode' => $this->type === 'qris' && $this->mode !== '' ? $this->mode : null,
            'fee_percent' => $this->feePercent,
            'needs_reference' => $this->needsReference,
            'is_active' => $this->isActive,
        ], $this->editing);

        Flux::modal('method-form')->close();
        Flux::toast(variant: 'success', text: __('payment_methods.saved', ['name' => $saved->name]));

        $this->resetForm();
        unset($this->methods);
    }

    public function move(string $methodId, int $direction): void
    {
        $method = $this->methods->firstWhere('id', $methodId);
        abort_unless($method instanceof PaymentMethod, 404);

        app(SavePaymentMethod::class)->move(auth()->user(), $this->outlet, $method, $direction);

        unset($this->methods);
    }

    public function resetForm(): void
    {
        $this->reset('editingId', 'name', 'type', 'mode', 'feePercent', 'needsReference', 'isActive');
        $this->resetErrorBag();
        unset($this->editing);
    }
}; ?>

<div class="flex w-full max-w-4xl flex-col gap-6">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <flux:heading size="xl" class="font-display">{{ __('payment_methods.title') }}</flux:heading>
            <flux:text class="mt-1 text-content-muted">{{ __('payment_methods.subtitle') }}</flux:text>
        </div>

        <flux:button variant="primary" icon="plus" wire:click="create" class="min-h-11" data-test="method-add">{{ __('payment_methods.add') }}</flux:button>
    </div>

    <div class="relative overflow-x-auto rounded-xl border border-line bg-surface">
        <table class="report-table" data-test="methods-table">
            <thead>
                <tr>
                    <th scope="col" class="text-start">{{ __('payment_methods.name') }}</th>
                    <th scope="col" class="text-start">{{ __('payment_methods.kind') }}</th>
                    <th scope="col">{{ __('payment_methods.fee') }}</th>
                    <th scope="col" class="text-start">{{ __('payment_methods.status') }}</th>
                    <th scope="col"><span class="sr-only">{{ __('payment_methods.edit') }}</span></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($this->methods as $method)
                    <tr wire:key="method-{{ $method->id }}" data-test="method-row-{{ $method->id }}" @class(['text-content-muted' => ! $method->is_active])>
                        <th scope="row" class="text-start font-medium">{{ $method->name }}</th>
                        <td class="text-start">{{ __('payment_methods.kinds.'.($method->type === 'qris' ? $method->mode : $method->type)) }}</td>
                        <td class="numeric">{{ \Illuminate\Support\Number::format((float) $method->fee_percent, maxPrecision: 2, locale: 'id') }}%</td>
                        <td class="text-start">
                            <flux:badge size="sm" :color="$method->is_active ? 'green' : 'zinc'">
                                {{ $method->is_active ? __('payment_methods.active') : __('payment_methods.inactive') }}
                            </flux:badge>
                        </td>
                        <td>
                            <div class="flex justify-end gap-1">
                                <flux:button size="sm" variant="ghost" icon="chevron-up" wire:click="move('{{ $method->id }}', -1)" :disabled="$loop->first" class="min-h-11 min-w-11" :aria-label="__('payment_methods.move_up', ['name' => $method->name])" />
                                <flux:button size="sm" variant="ghost" icon="chevron-down" wire:click="move('{{ $method->id }}', 1)" :disabled="$loop->last" class="min-h-11 min-w-11" :aria-label="__('payment_methods.move_down', ['name' => $method->name])" />
                                <flux:button size="sm" icon="pencil-square" wire:click="edit('{{ $method->id }}')" class="min-h-11" data-test="method-edit-{{ $method->id }}" :aria-label="__('payment_methods.edit_named', ['name' => $method->name])">
                                    <span class="max-sm:sr-only">{{ __('payment_methods.edit') }}</span>
                                </flux:button>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <flux:modal name="method-form" class="w-full max-w-lg" @close="$wire.resetForm()">
        <form wire:submit="save" class="flex flex-col gap-5" data-test="method-form">
            <flux:heading size="lg">{{ $editingId === null ? __('payment_methods.add') : __('payment_methods.edit') }}</flux:heading>

            <flux:input wire:model="name" :label="__('payment_methods.name')" required data-test="method-name" />

            @if ($editingId === null)
                <flux:select wire:model="type" :label="__('payment_methods.type')" data-test="method-type">
                    @foreach (\App\Domain\Sales\SavePaymentMethod::NEW_TYPES as $option)
                        <flux:select.option :value="$option">{{ __('payment_methods.kinds.'.$option) }}</flux:select.option>
                    @endforeach
                </flux:select>
            @elseif ($type === 'qris')
                <flux:radio.group wire:model="mode" :label="__('payment_methods.qris_mode')" data-test="method-mode">
                    <flux:radio :value="\App\Domain\Sales\Models\PaymentMethod::GATEWAY_QRIS" :label="__('payment_methods.kinds.gateway_qris')" :description="__('payment_methods.gateway_hint')" />
                    <flux:radio :value="\App\Domain\Sales\Models\PaymentMethod::STATIC_QRIS" :label="__('payment_methods.kinds.static_qris')" :description="__('payment_methods.static_hint')" />
                </flux:radio.group>
            @endif

            <flux:input wire:model="feePercent" inputmode="decimal" :label="__('payment_methods.fee')" :description="__('payment_methods.fee_hint')" class="tabular-nums" data-test="method-fee">
                <x-slot name="iconTrailing"><span class="text-content-muted" aria-hidden="true">%</span></x-slot>
            </flux:input>

            @if (! in_array($type, ['cash', 'qris'], true))
                <flux:switch wire:model="needsReference" :label="__('payment_methods.needs_reference')" :description="__('payment_methods.needs_reference_hint')" data-test="method-reference" />
            @endif

            <flux:switch wire:model="isActive" :label="__('payment_methods.is_active')" data-test="method-active" />
            <flux:error name="isActive" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button class="min-h-11">{{ __('payment_methods.cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary" class="min-h-11" data-test="method-save">{{ __('payment_methods.save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
