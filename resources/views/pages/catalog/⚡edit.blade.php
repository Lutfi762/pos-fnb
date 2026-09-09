<?php

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\SaveProduct;
use App\Support\Money;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::app')] class extends Component {
    public ?Product $product = null;

    public string $name = '';

    public string $categoryId = '';

    public string $station = '';

    public string $description = '';

    public bool $isActive = true;

    /** @var list<array{id: string|null, name: string, price: string, is_active: bool}> */
    public array $variants = [];

    public function mount(?Product $product = null): void
    {
        if (! $product instanceof Product) {
            $this->variants = [$this->blankVariant()];

            return;
        }

        $this->product = $product->load('variants');
        $this->name = $product->name;
        $this->categoryId = $product->category_id;
        $this->station = $product->station ?? '';
        $this->description = $product->description ?? '';
        $this->isActive = $product->is_active;

        $this->variants = $product->variants
            ->sortBy('sort_order')
            ->map(fn ($variant): array => [
                'id' => $variant->id,
                'name' => $variant->name,
                'price' => Money::format($variant->base_price, withPrefix: false),
                'is_active' => $variant->is_active,
            ])
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, Category>
     */
    #[Computed]
    public function categories(): Collection
    {
        return Category::query()->orderBy('sort_order')->orderBy('name')->get();
    }

    public function addVariant(): void
    {
        $this->variants[] = $this->blankVariant();
    }

    public function removeVariant(int $index): void
    {
        unset($this->variants[$index]);

        $this->variants = array_values($this->variants);

        // A product with no variant has nothing to sell, so keep one row.
        if ($this->variants === []) {
            $this->variants = [$this->blankVariant()];
        }
    }

    public function save(SaveProduct $action): void
    {
        $data = $this->validate();

        $product = $action->handle(
            $this->product,
            [
                'name' => $data['name'],
                'category_id' => $data['categoryId'],
                'station' => $data['station'] === '' ? null : $data['station'],
                'description' => $data['description'] === '' ? null : $data['description'],
                'is_active' => $this->isActive,
            ],
            array_map(fn (array $variant): array => [
                'id' => $variant['id'],
                'name' => $variant['name'],
                'base_price' => Money::parse($variant['price']),
                'is_active' => $variant['is_active'],
            ], $data['variants']),
        );

        $this->product = $product;

        Flux::toast(variant: 'success', text: __('catalog.saved'));

        $this->redirect(route('catalog.index'), navigate: true);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'categoryId' => ['required', 'string', 'exists:categories,id'],
            'station' => ['nullable', 'string', 'in:,bar,kitchen'],
            'description' => ['nullable', 'string', 'max:500'],
            'variants' => ['required', 'array', 'min:1'],
            'variants.*.id' => ['nullable', 'string'],
            'variants.*.name' => ['required', 'string', 'max:60'],
            'variants.*.price' => ['required', 'string'],
            'variants.*.is_active' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['variants.min' => __('catalog.variant_required')];
    }

    /**
     * @return array{id: null, name: string, price: string, is_active: bool}
     */
    private function blankVariant(): array
    {
        return ['id' => null, 'name' => '', 'price' => '', 'is_active' => true];
    }
}; ?>

<div class="flex w-full max-w-3xl flex-col gap-6">
    <div>
        <flux:button
            size="sm"
            variant="subtle"
            icon="arrow-left"
            :href="route('catalog.index')"
            wire:navigate
            class="-ms-2"
        >
            {{ __('catalog.back') }}
        </flux:button>

        <flux:heading size="xl" class="mt-2 font-display">
            {{ $product ? __('catalog.edit_product') : __('catalog.new_product') }}
        </flux:heading>
    </div>

    <form wire:submit="save" class="flex flex-col gap-6">
        <div class="grid gap-4 rounded-xl border border-line bg-surface p-4 sm:grid-cols-2">
            <flux:input wire:model="name" :label="__('catalog.name')" required autofocus data-test="name" />

            <flux:select wire:model="categoryId" :label="__('catalog.category')" required data-test="category">
                <flux:select.option value="">{{ __('catalog.category') }}</flux:select.option>
                @foreach ($this->categories as $category)
                    <flux:select.option value="{{ $category->id }}">{{ $category->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model="station" :label="__('catalog.station')" data-test="station">
                <flux:select.option value="">{{ __('catalog.no_station') }}</flux:select.option>
                <flux:select.option value="bar">{{ __('catalog.station_bar') }}</flux:select.option>
                <flux:select.option value="kitchen">{{ __('catalog.station_kitchen') }}</flux:select.option>
            </flux:select>

            <flux:field variant="inline" class="self-end pb-2">
                <flux:checkbox wire:model="isActive" data-test="is-active" />
                <flux:label>{{ __('catalog.active') }}</flux:label>
            </flux:field>

            <flux:textarea
                wire:model="description"
                :label="__('catalog.description')"
                rows="2"
                class="sm:col-span-2"
            />
        </div>

        <div class="flex flex-col gap-3 rounded-xl border border-line bg-surface p-4">
            <div>
                <flux:heading size="lg">{{ __('catalog.variants') }}</flux:heading>
                <flux:text class="text-sm text-content-muted">{{ __('catalog.default_variant_hint') }}</flux:text>
                <flux:text class="text-sm text-content-muted">{{ __('catalog.price_hint') }}</flux:text>
            </div>

            @error('variants')
                <flux:text class="text-danger">{{ $message }}</flux:text>
            @enderror

            @foreach ($variants as $index => $variant)
                <div wire:key="variant-{{ $index }}" class="flex flex-wrap items-end gap-3">
                    <flux:input
                        wire:model="variants.{{ $index }}.name"
                        :label="__('catalog.variant')"
                        class="min-w-40 flex-1"
                        required
                        data-test="variant-name-{{ $index }}"
                    />

                    <flux:input
                        wire:model="variants.{{ $index }}.price"
                        :label="__('catalog.base_price')"
                        inputmode="numeric"
                        class="numeric max-w-36 text-end"
                        required
                        data-test="variant-price-{{ $index }}"
                    />

                    <flux:field variant="inline" class="pb-2">
                        <flux:checkbox wire:model="variants.{{ $index }}.is_active" />
                        <flux:label>{{ __('catalog.active') }}</flux:label>
                    </flux:field>

                    <flux:button
                        type="button"
                        size="sm"
                        variant="subtle"
                        icon="trash"
                        class="mb-1"
                        wire:click="removeVariant({{ $index }})"
                        :tooltip="__('catalog.remove_variant')"
                        data-test="remove-variant-{{ $index }}"
                    />
                </div>
            @endforeach

            <div>
                <flux:button type="button" size="sm" icon="plus" wire:click="addVariant" data-test="add-variant">
                    {{ __('catalog.add_variant') }}
                </flux:button>
            </div>
        </div>

        <div>
            <flux:button variant="primary" type="submit" data-test="save">
                {{ __('catalog.save') }}
            </flux:button>
        </div>
    </form>
</div>
