<?php

use App\Domain\Identity\CurrentOutlet;
use App\Domain\Identity\Models\Outlet;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::auth')] #[Title('Pilih outlet')] class extends Component {
    /**
     * Outlets the signed in user may open.
     *
     * @return Collection<int, Outlet>
     */
    #[Computed]
    public function outlets(): Collection
    {
        return Auth::user()->accessibleOutlets();
    }

    public function choose(string $outletId, CurrentOutlet $current): void
    {
        $user = Auth::user();

        $outlet = $this->outlets->firstWhere('id', $outletId);

        abort_unless($outlet instanceof Outlet, 403);

        $current->set($outlet, $user);

        $this->redirectIntended(default: route('dashboard', absolute: false), navigate: true);
    }
}; ?>

<div class="flex flex-col gap-6">
    <x-auth-header :title="__('outlet.select_title')" :description="__('outlet.select_description')" />

    @if ($this->outlets->isEmpty())
        <x-empty-state
            icon="building-storefront"
            :heading="__('outlet.none_heading')"
            :description="__('outlet.none_description')"
        />
    @else
        <div class="flex flex-col gap-2">
            @foreach ($this->outlets as $outlet)
                <button
                    type="button"
                    wire:click="choose('{{ $outlet->id }}')"
                    wire:key="outlet-{{ $outlet->id }}"
                    class="flex items-center justify-between rounded-lg border border-line bg-surface p-4 text-start transition hover:border-brand"
                >
                    <span>
                        <span class="block font-medium text-content">{{ $outlet->name }}</span>
                        <span class="block font-mono text-xs text-content-muted">{{ $outlet->code }}</span>
                    </span>

                    <flux:icon.chevron-right class="size-4 text-content-muted" />
                </button>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <flux:button type="submit" variant="subtle" class="w-full" data-test="logout-button">
            {{ __('Log out') }}
        </flux:button>
    </form>
</div>
</x-layouts::auth>
