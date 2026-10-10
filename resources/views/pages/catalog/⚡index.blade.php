<?php

use App\Domain\Catalog\ImportMenu;
use App\Domain\Catalog\MenuCatalog;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Identity\CurrentOutlet;
use App\Domain\Identity\Models\Outlet;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts::app')] #[Title('Catalog')] class extends Component {
    use WithFileUploads;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'kategori', except: '')]
    public string $categoryId = '';

    #[Url(as: 'arsip', except: false)]
    public bool $withArchived = false;

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $sheet = null;

    /**
     * What the file said, for the manager to look over. Display only: the
     * import itself reads the file again, so nothing typed into the browser
     * can slip past the checks.
     *
     * @var list<array<string, mixed>>
     */
    #[Locked]
    public array $preview = [];

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

    public function checkSheet(): void
    {
        $this->validate(['sheet' => ['required', 'file', 'max:5120']], attributes: ['sheet' => __('catalog.import.file')]);

        try {
            $this->preview = app(ImportMenu::class)->read($this->sheet->getRealPath());
        } catch (RuntimeException $exception) {
            $this->preview = [];
            $this->addError('sheet', $exception->getMessage());
        }
    }

    public function importSheet(bool $skipInvalid = false): void
    {
        abort_if($this->sheet === null, 422);

        $import = app(ImportMenu::class);

        try {
            $result = $import->commit($import->read($this->sheet->getRealPath()), $skipInvalid);
        } catch (RuntimeException|\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->addError('sheet', $exception->getMessage());

            return;
        }

        $this->cancelImport();
        unset($this->products, $this->categories);

        Flux::modal('import-menu')->close();
        Flux::toast(variant: 'success', text: $result['skipped'] > 0
            ? __('catalog.import.done_skipped', $result)
            : __('catalog.import.done', $result));
    }

    public function cancelImport(): void
    {
        $this->sheet?->delete();
        $this->reset('sheet', 'preview');
        $this->resetErrorBag();
    }

    public function updatedSheet(): void
    {
        $this->preview = [];
        $this->resetErrorBag('sheet');
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

        <div class="flex flex-wrap gap-2">
            <flux:modal.trigger name="import-menu">
                <flux:button icon="arrow-up-tray" data-test="open-import">{{ __('catalog.import.button') }}</flux:button>
            </flux:modal.trigger>

            <flux:button variant="primary" icon="plus" :href="route('catalog.create')" wire:navigate data-test="add-product">
                {{ __('catalog.add_product') }}
            </flux:button>
        </div>
    </div>

    <flux:modal name="import-menu" class="w-full max-w-3xl" @close="$wire.cancelImport()">
        <div class="flex flex-col gap-5">
            <div>
                <flux:heading size="lg">{{ __('catalog.import.title') }}</flux:heading>
                <flux:text class="mt-2">{{ __('catalog.import.intro') }}</flux:text>
            </div>

            <div class="flex flex-wrap items-end gap-3">
                <flux:input type="file" wire:model="sheet" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" :label="__('catalog.import.file')" class="min-w-0 flex-1" data-test="import-file" />

                <flux:button :href="route('catalog.import.template')" icon="arrow-down-tray" class="min-h-11" data-test="import-template">
                    {{ __('catalog.import.template') }}
                </flux:button>
            </div>

            @if ($preview === [])
                <div class="flex justify-end">
                    <flux:button variant="primary" wire:click="checkSheet" wire:target="sheet,checkSheet" wire:loading.attr="disabled" class="min-h-11" data-test="import-check">
                        <span wire:loading.remove wire:target="checkSheet">{{ __('catalog.import.check') }}</span>
                        <span wire:loading wire:target="checkSheet">{{ __('catalog.import.checking') }}</span>
                    </flux:button>
                </div>
            @else
                @php($invalid = collect($preview)->filter(fn ($row) => $row['errors'] !== [])->count())
                @php($valid = count($preview) - $invalid)

                <flux:text data-test="import-summary">
                    {{ __('catalog.import.preview', ['total' => count($preview), 'valid' => $valid, 'invalid' => $invalid]) }}
                </flux:text>

                <div class="max-h-80 overflow-auto rounded-lg border border-line">
                    <table class="report-table" data-test="import-preview">
                        <thead>
                            <tr>
                                <th scope="col">{{ __('catalog.import.row') }}</th>
                                <th scope="col" class="text-start">{{ __('catalog.category') }}</th>
                                <th scope="col" class="text-start">{{ __('catalog.product') }}</th>
                                <th scope="col" class="text-start">{{ __('catalog.variant') }}</th>
                                <th scope="col">{{ __('catalog.price') }}</th>
                                <th scope="col" class="text-start">{{ __('catalog.import.status') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($preview as $row)
                                <tr wire:key="import-{{ $row['row'] }}" @class(['bg-danger-surface' => $row['errors'] !== []])>
                                    <td class="numeric">{{ $row['row'] }}</td>
                                    <td class="text-start">{{ $row['category'] }}</td>
                                    <td class="text-start">{{ $row['product'] }}</td>
                                    <td class="text-start">{{ $row['variant'] }}</td>
                                    <td>@if ($row['price'] !== null)<x-money :amount="$row['price']" />@endif</td>
                                    <td class="text-start wrap">
                                        @if ($row['errors'] === [])
                                            <span class="text-done-text">{{ __('catalog.import.ok') }}</span>
                                        @else
                                            <span class="text-danger-text" data-test="import-error-{{ $row['row'] }}">{{ implode(' ', $row['errors']) }}</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="flex flex-wrap justify-end gap-2">
                    <flux:button wire:click="cancelImport" class="min-h-11">{{ __('catalog.import.cancel') }}</flux:button>

                    @if ($invalid === 0)
                        <flux:button variant="primary" wire:click="importSheet" class="min-h-11" data-test="import-commit">
                            {{ __('catalog.import.commit', ['count' => $valid]) }}
                        </flux:button>
                    @elseif ($valid > 0)
                        <flux:button variant="primary" wire:click="importSheet(true)" class="min-h-11" data-test="import-commit-skip">
                            {{ __('catalog.import.commit_skip', ['count' => $valid]) }}
                        </flux:button>
                    @endif
                </div>
            @endif

            @error('sheet')
                <flux:text class="text-danger-text" data-test="import-file-error">{{ $message }}</flux:text>
            @enderror
        </div>
    </flux:modal>

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
        <div class="relative overflow-x-auto rounded-xl border border-line bg-surface">
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
