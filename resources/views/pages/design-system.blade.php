<x-layouts::app :title="__('design.title')">
    <div class="flex w-full flex-col gap-8">
        <div>
            <flux:heading size="xl" class="font-display">{{ __('design.title') }}</flux:heading>
            <flux:text class="mt-1 text-content-muted">{{ __('design.subtitle') }}</flux:text>
        </div>

        <section aria-labelledby="ds-color">
            <flux:heading id="ds-color" size="lg" class="font-display">{{ __('design.colors') }}</flux:heading>

            <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ([
                    'brand' => 'bg-brand text-brand-contrast',
                    'brand-dark' => 'bg-brand-dark text-white',
                    'pending' => 'bg-pending-surface text-pending-text',
                    'danger' => 'bg-danger-surface text-danger-text',
                    'done' => 'bg-done-surface text-done-text',
                    'surface' => 'bg-surface text-content border border-line',
                    'surface-sunken' => 'bg-surface-sunken text-content',
                    'base' => 'bg-base text-content border border-line',
                ] as $name => $classes)
                    <div class="{{ $classes }} flex min-h-20 flex-col justify-end rounded-lg p-3">
                        <span class="font-mono text-xs">{{ $name }}</span>
                    </div>
                @endforeach
            </div>
        </section>

        <section aria-labelledby="ds-type">
            <flux:heading id="ds-type" size="lg" class="font-display">{{ __('design.typography') }}</flux:heading>

            <div class="mt-3 space-y-2 rounded-lg border border-line bg-surface p-4">
                <p class="font-display text-3xl font-semibold">Bricolage Grotesque</p>
                <p class="text-base">Plus Jakarta Sans — {{ __('design.body_sample') }}</p>
                <p class="font-mono">JetBrains Mono — ORD-000123</p>
                <p class="numeric text-xl">{{ \App\Support\Money::format(1234567) }}</p>
            </div>
        </section>

        <section aria-labelledby="ds-money">
            <flux:heading id="ds-money" size="lg" class="font-display">{{ __('design.money') }}</flux:heading>

            <table class="mt-3 w-full max-w-sm border-collapse overflow-hidden rounded-lg border border-line bg-surface">
                <tbody>
                    @foreach ([25000, 7500, 1234567, -50000] as $amount)
                        <tr class="border-b border-line last:border-0">
                            <td class="px-4 py-3">{{ __('design.amount') }}</td>
                            <td class="px-4 py-3"><x-money :amount="$amount" class="block" /></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>

        <section aria-labelledby="ds-controls">
            <flux:heading id="ds-controls" size="lg" class="font-display">{{ __('design.controls') }}</flux:heading>

            <div class="mt-3 flex flex-wrap items-center gap-3">
                <flux:button variant="primary">{{ __('design.primary') }}</flux:button>
                <flux:button>{{ __('design.secondary') }}</flux:button>
                <flux:button variant="danger">{{ __('design.destructive') }}</flux:button>
                <flux:badge color="green">{{ __('design.paid') }}</flux:badge>
                <flux:badge color="amber">{{ __('design.pending') }}</flux:badge>
            </div>

            <div class="mt-4 grid max-w-md gap-3">
                <flux:input :label="__('design.field')" placeholder="Kopi Susu" />
                <flux:select :label="__('design.choice')">
                    <flux:select.option>Dine in</flux:select.option>
                    <flux:select.option>Take away</flux:select.option>
                </flux:select>
            </div>
        </section>

        <section aria-labelledby="ds-empty">
            <flux:heading id="ds-empty" size="lg" class="font-display">{{ __('design.empty') }}</flux:heading>

            <div class="mt-3">
                <x-empty-state
                    icon="inbox"
                    :heading="__('design.empty_heading')"
                    :description="__('design.empty_description')"
                />
            </div>
        </section>
    </div>
</x-layouts::app>
