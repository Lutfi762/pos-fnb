<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Domain\Audit\Audit;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\ModifierGroup;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Support\Money;
use App\Support\Spreadsheet\XlsxReader;
use App\Support\Spreadsheet\XlsxWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Loads a menu from a spreadsheet: one row per variant.
 *
 * Two steps, on purpose. Read first, which checks every row and reports the
 * broken ones by their row number without writing anything. Then commit,
 * which either writes everything or, when the manager chose to skip the
 * broken rows, everything else. Never half a file.
 *
 * Rows with the same category and product name are one product with several
 * variants. A product that already exists is updated: its variants are
 * matched by name, new ones added, prices changed. Nothing is deleted.
 */
class ImportMenu
{
    public const HEADERS = ['kategori', 'nama_produk', 'varian', 'harga', 'sku', 'grup_modifier'];

    public function __construct(
        private readonly XlsxReader $reader,
        private readonly Audit $audit,
    ) {}

    /**
     * The template a manager fills in, with three example rows. The modifier
     * example names a group this business really has, so importing the
     * template untouched works instead of greeting them with errors.
     */
    public function template(): string
    {
        $group = (string) ModifierGroup::query()->orderBy('name')->value('name');

        return (new XlsxWriter)
            ->sheet(__('catalog.import.sheet_menu'), self::HEADERS, [
                XlsxWriter::TEXT, XlsxWriter::TEXT, XlsxWriter::TEXT, XlsxWriter::MONEY, XlsxWriter::TEXT, XlsxWriter::TEXT,
            ], [
                ['Kopi', 'Es Kopi Susu', 'Reguler', 22000, '', $group],
                ['Kopi', 'Es Kopi Susu', 'Besar', 28000, '', $group],
                ['Makanan', 'Roti Bakar Coklat', '', 24000, '', ''],
            ])
            ->sheet(__('catalog.import.sheet_help'), [__('catalog.import.help_column'), __('catalog.import.help_meaning')], [XlsxWriter::TEXT, XlsxWriter::TEXT], [
                ['kategori', __('catalog.import.help.kategori')],
                ['nama_produk', __('catalog.import.help.nama_produk')],
                ['varian', __('catalog.import.help.varian')],
                ['harga', __('catalog.import.help.harga')],
                ['sku', __('catalog.import.help.sku')],
                ['grup_modifier', __('catalog.import.help.grup_modifier')],
            ])
            ->toString();
    }

    /**
     * Read and check a file. Nothing is written.
     *
     * @return list<array{row: int, category: string, product: string, variant: string, price: int|null, sku: string|null, groups: list<string>, errors: list<string>}>
     */
    public function read(string $path): array
    {
        try {
            $rows = $this->reader->rows($path);
        } catch (RuntimeException) {
            throw new RuntimeException(__('catalog.import.not_xlsx'));
        }

        $header = array_map(fn ($cell): string => Str::snake(trim(mb_strtolower((string) $cell))), $rows[0] ?? []);
        $positions = [];

        foreach (self::HEADERS as $name) {
            $position = array_search($name, $header, true);

            if ($position === false && in_array($name, ['kategori', 'nama_produk', 'harga'], true)) {
                throw new RuntimeException(__('catalog.import.missing_column', ['column' => $name]));
            }

            $positions[$name] = $position === false ? null : (int) $position;
        }

        $groups = ModifierGroup::query()->get(['id', 'name'])
            ->mapWithKeys(fn (ModifierGroup $group): array => [mb_strtolower($group->name) => $group->getKey()]);
        $existingSkus = ProductVariant::query()->whereNotNull('sku')->pluck('product_id', 'sku')
            ->mapWithKeys(fn ($productId, $sku): array => [mb_strtolower((string) $sku) => $productId]);

        $parsed = [];
        $seenSkus = [];
        $seenVariants = [];

        foreach (array_slice($rows, 1, null, true) as $index => $row) {
            $cell = fn (string $name): mixed => $positions[$name] === null ? null : ($row[$positions[$name]] ?? null);

            if (array_filter($row, fn ($value): bool => $value !== null && $value !== '') === []) {
                continue;
            }

            $line = [
                'row' => $index + 1,
                'category' => trim((string) $cell('kategori')),
                'product' => trim((string) $cell('nama_produk')),
                'variant' => trim((string) $cell('varian')) ?: __('catalog.import.default_variant'),
                'price' => $this->price($cell('harga')),
                'sku' => ($sku = trim((string) $cell('sku'))) === '' ? null : $sku,
                'groups' => array_values(array_filter(array_map('trim', explode(',', (string) $cell('grup_modifier'))))),
                'errors' => [],
            ];

            if ($line['category'] === '') {
                $line['errors'][] = __('catalog.import.error.category');
            }

            if ($line['product'] === '') {
                $line['errors'][] = __('catalog.import.error.product');
            }

            if ($line['price'] === null) {
                $line['errors'][] = __('catalog.import.error.price');
            }

            $variantKey = mb_strtolower($line['category'].'|'.$line['product'].'|'.$line['variant']);

            if (isset($seenVariants[$variantKey])) {
                $line['errors'][] = __('catalog.import.error.duplicate_variant', ['row' => $seenVariants[$variantKey]]);
            }

            $seenVariants[$variantKey] = $line['row'];

            if ($line['sku'] !== null) {
                $skuKey = mb_strtolower($line['sku']);

                if (isset($seenSkus[$skuKey])) {
                    $line['errors'][] = __('catalog.import.error.duplicate_sku', ['row' => $seenSkus[$skuKey]]);
                }

                $owner = $existingSkus->get($skuKey);
                $sameProduct = $owner !== null && Product::query()->whereKey($owner)->where('name', $line['product'])->exists();

                if ($owner !== null && ! $sameProduct) {
                    $line['errors'][] = __('catalog.import.error.sku_taken', ['sku' => $line['sku']]);
                }

                $seenSkus[$skuKey] = $line['row'];
            }

            foreach ($line['groups'] as $group) {
                if (! $groups->has(mb_strtolower($group))) {
                    $line['errors'][] = __('catalog.import.error.group', ['group' => $group]);
                }
            }

            $parsed[] = $line;
        }

        if ($parsed === []) {
            throw new RuntimeException(__('catalog.import.empty'));
        }

        return $parsed;
    }

    /**
     * Write the checked rows. Refuses outright when any row is broken,
     * unless the manager chose to leave the broken ones out.
     *
     * @param  list<array{row: int, category: string, product: string, variant: string, price: int|null, sku: string|null, groups: list<string>, errors: list<string>}>  $rows
     * @return array{products: int, variants: int, skipped: int}
     */
    public function commit(array $rows, bool $skipInvalid): array
    {
        $invalid = array_filter($rows, fn (array $row): bool => $row['errors'] !== []);

        abort_if($invalid !== [] && ! $skipInvalid, 422, __('catalog.import.has_errors', ['count' => count($invalid)]));

        $valid = array_values(array_filter($rows, fn (array $row): bool => $row['errors'] === []));

        $result = DB::transaction(function () use ($valid): array {
            $groups = ModifierGroup::query()->get(['id', 'name'])
                ->mapWithKeys(fn (ModifierGroup $group): array => [mb_strtolower($group->name) => $group->getKey()]);
            $products = 0;
            $variants = 0;

            foreach (collect($valid)->groupBy(fn (array $row): string => mb_strtolower($row['category'].'|'.$row['product'])) as $lines) {
                $first = $lines->first();
                $category = $this->category($first['category']);

                $product = Product::query()->where('category_id', $category->getKey())->where('name', $first['product'])->first()
                    ?? Product::create([
                        'category_id' => $category->getKey(),
                        'name' => $first['product'],
                        'is_active' => true,
                        'sort_order' => (int) Product::query()->where('category_id', $category->getKey())->max('sort_order') + 1,
                    ]);

                $products++;
                $position = (int) $product->variants()->max('sort_order');

                foreach ($lines as $line) {
                    $variant = $product->variants()->where('name', $line['variant'])->first();
                    $values = ['base_price' => $line['price'], 'sku' => $line['sku'] ?? $variant?->sku, 'is_active' => true];

                    if ($variant instanceof ProductVariant) {
                        $variant->update($values);
                    } else {
                        $product->variants()->create($values + [
                            'name' => $line['variant'],
                            'is_default' => ! $product->variants()->exists(),
                            'sort_order' => ++$position,
                        ]);
                    }

                    $variants++;
                }

                $ids = $lines->flatMap(fn (array $line): array => $line['groups'])
                    ->map(fn (string $name): ?string => $groups->get(mb_strtolower($name)))
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();

                if ($ids !== []) {
                    $product->modifierGroups()->syncWithoutDetaching($ids);
                }
            }

            return ['products' => $products, 'variants' => $variants];
        });

        MenuCatalog::flush();

        $summary = $result + ['skipped' => count($invalid)];
        $this->audit->record('catalog.imported', after: $summary);

        return $summary;
    }

    private function category(string $name): Category
    {
        $existing = Category::query()->whereRaw('lower(name) = ?', [mb_strtolower($name)])->first();

        if ($existing instanceof Category) {
            return $existing;
        }

        $slug = Str::slug($name);
        $base = $slug;
        $suffix = 1;

        while (Category::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.++$suffix;
        }

        return Category::create([
            'name' => $name,
            'slug' => $slug,
            'sort_order' => (int) Category::query()->max('sort_order') + 1,
            'is_active' => true,
        ]);
    }

    /**
     * A price as typed in any of the ways people type prices: 25000,
     * 25.000, "Rp 25.000". Negative or unreadable is null.
     */
    private function price(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        if (is_float($value)) {
            return $value >= 0 ? (int) round($value) : null;
        }

        if (! is_string($value) || preg_match('/\d/', $value) !== 1) {
            return null;
        }

        $amount = Money::parse($value);

        return $amount >= 0 ? $amount : null;
    }
}
