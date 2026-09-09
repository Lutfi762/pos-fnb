{{-- The bottom of the bill. Every row is tabular and right aligned. --}}
<dl class="pos-summary">
    <div class="pos-summary-row">
        <dt>{{ __('pos.subtotal') }}</dt>
        <dd><x-money :amount="$this->totals->subtotal" data-test="subtotal" /></dd>
    </div>

    @if ($this->totals->discountTotal() > 0)
        <div class="pos-summary-row pos-summary-discount">
            <dt>{{ __('pos.discount') }}</dt>
            <dd><x-money :amount="-$this->totals->discountTotal()" data-test="discount-total" /></dd>
        </div>
    @endif

    @if ($this->totals->serviceChargeTotal > 0)
        <div class="pos-summary-row">
            <dt>{{ __('pos.service_charge') }} {{ $this->outlet->service_charge_percent + 0 }}%</dt>
            <dd><x-money :amount="$this->totals->serviceChargeTotal" /></dd>
        </div>
    @endif

    @if ($this->totals->taxTotal > 0)
        <div class="pos-summary-row">
            <dt>{{ __('pos.tax') }} {{ $this->outlet->tax_percent + 0 }}%</dt>
            <dd><x-money :amount="$this->totals->taxTotal" /></dd>
        </div>
    @endif

    @if ($this->totals->rounding !== 0)
        <div class="pos-summary-row">
            <dt>{{ __('pos.rounding') }}</dt>
            <dd><x-money :amount="$this->totals->rounding" /></dd>
        </div>
    @endif

    <div class="pos-summary-row pos-summary-grand">
        <dt>{{ __('pos.total') }}</dt>
        <dd><x-money :amount="$this->totals->grandTotal" data-test="grand-total" /></dd>
    </div>
</dl>

{{-- Bill discount. Over the limit, a supervisor has to sign it off. --}}
<details class="pos-bill-discount">
    <summary>{{ __('pos.bill_discount') }}</summary>

    <div class="pos-bill-discount-body">
        <flux:select wire:model="billDiscountType" size="sm" :label="__('pos.bill_discount')" label:class="sr-only">
            <flux:select.option value="">{{ __('pos.clear') }}</flux:select.option>
            <flux:select.option value="percent">{{ __('pos.discount_percent') }}</flux:select.option>
            <flux:select.option value="amount">{{ __('pos.discount_amount') }}</flux:select.option>
        </flux:select>

        <flux:input
            wire:model="billDiscountValue"
            type="number"
            min="0"
            size="sm"
            :label="__('pos.discount_value')"
            label:class="sr-only"
            data-test="bill-discount-value"
        />

        <flux:input
            wire:model="supervisorPin"
            type="password"
            inputmode="numeric"
            maxlength="6"
            size="sm"
            :label="__('pos.supervisor_pin')"
            :placeholder="__('pos.supervisor_pin')"
            label:class="sr-only"
            data-test="supervisor-pin"
        />

        <flux:button size="sm" wire:click="applyBillDiscount" data-test="apply-bill-discount">
            {{ __('pos.apply') }}
        </flux:button>
    </div>

    <flux:text size="sm" class="text-content-muted">
        {{ __('pos.supervisor_pin_hint', ['limit' => \App\Support\Money::format(20000)]) }}
    </flux:text>

    <flux:error name="supervisorPin" />
    <flux:error name="billDiscountValue" />
</details>

{{-- Thumb zone: the two buttons a cashier actually presses. --}}
<div class="pos-actions">
    <flux:button
        variant="subtle"
        class="pos-action"
        wire:click="saveBill"
        :disabled="$cart === []"
        data-test="save-bill"
    >
        {{ __('pos.save_bill') }}
    </flux:button>

    <flux:button
        variant="primary"
        class="pos-action"
        wire:click="saveBill"
        :disabled="$cart === []"
        data-test="pay"
    >
        {{ __('pos.pay') }} <kbd class="pos-kbd">F2</kbd>
    </flux:button>
</div>
