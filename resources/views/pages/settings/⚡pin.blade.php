<?php

use App\Domain\Identity\StaffPins;
use Flux\Flux;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('PIN settings')] class extends Component {
    public string $pin = '';

    public string $pin_confirmation = '';

    public function save(): void
    {
        $this->validate([
            'pin' => ['required', 'digits:'.StaffPins::LENGTH, 'confirmed'],
        ], attributes: ['pin' => __('staff.own_pin.new')]);

        try {
            app(StaffPins::class)->change(auth()->user(), $this->pin);
        } finally {
            $this->reset('pin', 'pin_confirmation');
        }

        Flux::toast(variant: 'success', text: __('staff.own_pin.saved'));
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('staff.own_pin.title') }}</flux:heading>

    <x-pages::settings.layout :heading="__('staff.own_pin.title')" :subheading="__('staff.own_pin.subtitle')">
        <form wire:submit="save" class="flex flex-col gap-5" data-test="own-pin-form">
            <flux:callout icon="key">
                <flux:callout.text>{{ auth()->user()->pin_hash === null ? __('staff.own_pin.none_yet') : __('staff.own_pin.current_state') }}</flux:callout.text>
            </flux:callout>

            <flux:input wire:model="pin" type="password" inputmode="numeric" maxlength="{{ \App\Domain\Identity\StaffPins::LENGTH }}" :label="__('staff.own_pin.new')" autocomplete="off" required data-test="own-pin" />
            <flux:input wire:model="pin_confirmation" type="password" inputmode="numeric" maxlength="{{ \App\Domain\Identity\StaffPins::LENGTH }}" :label="__('staff.own_pin.confirm')" autocomplete="off" required data-test="own-pin-confirmation" />

            <div class="flex justify-end">
                <flux:button type="submit" variant="primary" class="min-h-11" data-test="own-pin-save">{{ __('staff.own_pin.save') }}</flux:button>
            </div>
        </form>
    </x-pages::settings.layout>
</section>
