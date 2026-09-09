<?php

use App\Domain\Catalog\MenuCatalog;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Identity\CurrentOutlet;
use App\Domain\Identity\Models\Outlet;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts::app')] #[Title('Katalog')] class extends Component {
    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'kategori', except: '')]
    public string $categoryId = '';

    #[Url(as: 'arsip', except: false)]
    public bool $withArchived = false;

    #[Computed]
    public function outlet(): Outlet
    {
        return app(CurrentOutlet::class)->getOrFail();
    }

    /**
     * @return Collection<int, Category>
     */
    #[Computed]
    public function categories(): Collection
    {
        return Category::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * ponytail: loads the whole menu in one query. A cafe carries dozens of
     * products, not thousands. Add pagination if a tenant passes ~500.
     *
     * @return Collection<int, Product>
     */
    #[Computed]
    public function products(): Collection
    {
        $outletId = $this->outlet->getKey();

        return Product::query()
            ->with([
                'category',
                'variants' => fn ($query) => $query->orderBy('sort_order'),
                'variants.outletPrices' => fn ($query) => $query->where('outlet_id', $outletId),
            ])
            ->when($this->withArchived, fn ($query) => $query->withTrashed())
            ->when($this->categoryId !== '', fn ($query) => $query->where('category_id', $this->categoryId))
            ->when($this->search !== '', function ($query): void {
                $term = '%'.$this->search.'%';

                $query->where(fn ($inner) => $inner->where('name', 'like', $term)->orWhere('sku', 'like', $term));
            })
            ->orderBy('name')
            ->get();
    }

    public function archive(string $productId): void
    {
        Product::query()->findOrFail($productId)->delete();

        MenuCatalog::flush();
        unset($this->products);

        Flux::toast(variant: 'success', text: __('catalog.archived_notice'));
    }

    public function restore(string $productId): void
    {
        Product::withTrashed()->findOrFail($productId)->restore();

        MenuCatalog::flush();
        unset($this->products);

        Flux::toast(variant: 'success', text: __('catalog.restored'));
    }
}; ?>

<div class="flex w-full flex-col gap-6">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <flux:heading size="xl" class="font-display">{{ __('catalog.title') }}</flux:heading>
            <flux:text class="mt-1 text-content-muted">
                {{ __('catalog.subtitle', ['outlet' => $this->outlet->name]) }}
            </flux:text>
        </div>

        <flux:button variant="primary" icon="plus" :href="route('catalog.create')" wire:navigate data-test="add-product">
            {{ __('catalog.add_product') }}
        </flux:button>
    </div>

    <div class="flex flex-wrap items-end gap-3">
        <flux:input
            wire:model.live.debounce.300ms="search"
            :label="__('catalog.search')"
            :placeholder="__('catalog.search_placeholder')"
            icon="magnifying-glass"
            class="max-w-xs"
            data-test="search"
        />

        <flux:select wire:model.live="categoryId" :label="__('catalog.category')" class="max-w-48">
            <flux:select.option value="">{{ __('catalog.all_categories') }}</flux:select.option>
            @foreach ($this->categories as $category)
                <flux:select.option value="{{ $category->id }}">{{ $category->name }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:checkbox wire:model.live="withArchived" :label="__('catalog.show_archived')" class="mb-2" />
    </div>

    @if ($this->products->isEmpty())
        <x-empty-state
            icon="squares-2x2"
            :heading="$search === '' && $categoryId === '' ? __('catalog.empty_heading') : __('catalog.no_results_heading')"
            :description="$search === '' && $categoryId === '' ? __('catalog.empty_description') : __('catalog.no_results_description')"
        />
    @else
        <div class="overflow-x-auto rounded-xl border border-line bg-surface">
            <table class="w-full border-collapse">
                <thead>
                    <tr class="border-b border-line text-sm text-content-muted">
                        <th scope="col" class="px-4 py-3 text-start font-medium">{{ __('catalog.product') }}</th>
                        <th scope="col" class="px-4 py-3 text-start font-medium">{{ __('catalog.variants') }}</th>
                        <th scope="col" class="px-4 py-3 text-end font-medium">{{ __('catalog.price') }}</th>
                        <th scope="col" class="px-4 py-3 text-end font-medium">
                            <span class="sr-only">{{ __('catalog.edit_product') }}</span>
                        </th>
                    </tr>
                </thead>

                <tbody>
                    @include('pages.catalog.rows')
                </tbody>
            </table>
        </div>
    @endif
</div>
