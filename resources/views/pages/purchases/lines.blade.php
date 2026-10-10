{{-- What was ordered, and how much of it has landed so far. --}}
<div class="relative overflow-x-auto rounded-xl border border-line bg-surface">
    <table class="w-full border-collapse">
        <thead>
            <tr class="border-b border-line text-sm text-content-muted">
                <th scope="col" class="px-4 py-3 text-start font-medium">{{ __('purchasing.item') }}</th>
                <th scope="col" class="px-4 py-3 text-end font-medium">{{ __('purchasing.quantity_ordered') }}</th>
                <th scope="col" class="px-4 py-3 text-end font-medium">{{ __('purchasing.quantity_received') }}</th>
                <th scope="col" class="px-4 py-3 text-end font-medium">{{ __('purchasing.unit_cost') }}</th>
                <th scope="col" class="px-4 py-3 text-end font-medium">{{ __('purchasing.line_total') }}</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($purchaseOrder->items as $line)
                <tr wire:key="line-{{ $line->id }}" class="border-b border-line last:border-0">
                    <td class="px-4 py-3">
                        <span class="block text-content">{{ $line->item_name }}</span>
                        <span class="block text-xs text-content-muted">{{ $line->unit_code }}</span>
                    </td>

                    <td class="px-4 py-3 text-end numeric tabular-nums">
                        {{ $this->amount((float) $line->quantity_ordered) }}
                    </td>

                    <td class="px-4 py-3 text-end numeric tabular-nums" data-test="received-{{ $line->id }}">
                        {{ $this->amount((float) $line->quantity_received) }}
                    </td>

                    <td class="px-4 py-3 text-end"><x-money :amount="$line->unit_cost" /></td>
                    <td class="px-4 py-3 text-end"><x-money :amount="$line->line_total" /></td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="px-4 py-6 text-center text-sm text-content-muted">
                        {{ __('purchasing.no_items_yet') }}
                    </td>
                </tr>
            @endforelse
        </tbody>

        <tfoot>
            <tr class="border-t border-line">
                <th scope="row" colspan="4" class="px-4 py-3 text-end font-medium text-content">
                    {{ __('purchasing.total') }}
                </th>

                <td class="px-4 py-3 text-end font-semibold" data-test="grand-total">
                    <x-money :amount="$purchaseOrder->grand_total" />
                </td>
            </tr>
        </tfoot>
    </table>
</div>
