<?php

declare(strict_types=1);

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Catalog\ImportMenu;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\ModifierGroup;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Identity\CurrentOutlet;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\Outlet;
use App\Models\User;
use App\Support\Spreadsheet\XlsxReader;
use App\Support\Spreadsheet\XlsxWriter;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    $this->outlet = Outlet::factory()->create();
    $this->manager = User::factory()->atOutlet($this->outlet, Role::Manager)->create();
    $this->sugar = ModifierGroup::factory()->create(['name' => 'Level gula']);

    $this->actingAs($this->manager);
    app(CurrentOutlet::class)->set($this->outlet);
});

/**
 * A menu spreadsheet as a manager would fill it in.
 *
 * @param  list<list<mixed>>  $rows
 * @param  list<string>|null  $headers
 */
function menuSheet(array $rows, ?array $headers = null): string
{
    $path = tempnam(sys_get_temp_dir(), 'menu').'.xlsx';
    $headers ??= ImportMenu::HEADERS;

    (new XlsxWriter)->sheet('Menu', $headers, array_fill(0, count($headers), XlsxWriter::TEXT), $rows)->save($path);

    return $path;
}

it('hands out a template the importer reads back without complaint', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'tpl');
    file_put_contents($path, app(ImportMenu::class)->template());

    $rows = app(ImportMenu::class)->read($path);

    expect($rows)->toHaveCount(3)
        ->and(collect($rows)->pluck('errors')->flatten()->all())->toBe([])
        ->and($rows[0]['price'])->toBe(22_000)
        ->and($rows[2]['variant'])->toBe('Reguler')
        ->and($rows[0]['groups'])->toBe(['Level gula']);

    ModifierGroup::query()->delete();
    file_put_contents($path, app(ImportMenu::class)->template());

    expect(collect(app(ImportMenu::class)->read($path))->pluck('errors')->flatten()->all())->toBe([]);
});

it('serves the template as a download to managers only', function (): void {
    $this->get(route('catalog.import.template'))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    $cashier = User::factory()->atOutlet($this->outlet, Role::Cashier)->create();

    $this->actingAs($cashier)->get(route('catalog.import.template'))->assertForbidden();
});

it('reads prices the way people type them', function (): void {
    $rows = app(ImportMenu::class)->read(menuSheet([
        ['Kopi', 'Americano', '', '25000', '', ''],
        ['Kopi', 'Latte', '', '28.000', '', ''],
        ['Kopi', 'Mocha', '', 'Rp 32.000', '', ''],
    ]));

    expect(array_column($rows, 'price'))->toBe([25_000, 28_000, 32_000]);
});

it('names every broken row by its spreadsheet row number and writes nothing', function (): void {
    $rows = app(ImportMenu::class)->read(menuSheet([
        ['Kopi', 'Latte', 'Reguler', '28000', 'LAT-R', 'Level gula'],
        ['', 'Mocha', '', '30000', '', ''],
        ['Kopi', 'Latte', 'reguler', '29000', '', ''],
        ['Kopi', 'Cappuccino', '', 'gratis', 'LAT-R', 'Topping'],
    ]));

    $errors = collect($rows)->mapWithKeys(fn (array $row): array => [$row['row'] => $row['errors']]);

    expect($errors[2])->toBe([])
        ->and($errors[3])->toBe([__('catalog.import.error.category')])
        ->and($errors[4])->toBe([__('catalog.import.error.duplicate_variant', ['row' => 2])])
        ->and($errors[5])->toBe([
            __('catalog.import.error.price'),
            __('catalog.import.error.duplicate_sku', ['row' => 2]),
            __('catalog.import.error.group', ['group' => 'Topping']),
        ])
        ->and(Product::query()->count())->toBe(0);
});

it('refuses the whole file when a row is broken, unless told to skip', function (): void {
    $import = app(ImportMenu::class);
    $rows = $import->read(menuSheet([
        ['Kopi', 'Latte', '', '28000', '', ''],
        ['Kopi', '', '', '30000', '', ''],
    ]));

    expect(fn () => $import->commit($rows, skipInvalid: false))->toThrow(HttpException::class);
    expect(Product::query()->count())->toBe(0)
        ->and(Category::query()->count())->toBe(0);

    $result = $import->commit($rows, skipInvalid: true);

    expect($result)->toBe(['products' => 1, 'variants' => 1, 'skipped' => 1])
        ->and(Product::query()->sole()->name)->toBe('Latte');
});

it('groups rows into products with variants and attaches modifier groups', function (): void {
    $import = app(ImportMenu::class);

    $result = $import->commit($import->read(menuSheet([
        ['Kopi', 'Es Kopi Susu', 'Reguler', '22000', 'EKS-R', 'level gula'],
        ['Kopi', 'Es Kopi Susu', 'Besar', '28000', 'EKS-B', ''],
        ['Makanan', 'Roti Bakar', '', '24000', '', ''],
    ])), skipInvalid: false);

    $coffee = Product::query()->with(['variants', 'modifierGroups', 'category'])->where('name', 'Es Kopi Susu')->sole();

    expect($result)->toBe(['products' => 2, 'variants' => 3, 'skipped' => 0])
        ->and($coffee->category->name)->toBe('Kopi')
        ->and($coffee->variants->pluck('base_price', 'name')->all())->toBe(['Reguler' => 22_000, 'Besar' => 28_000])
        ->and($coffee->variants->firstWhere('name', 'Reguler')->is_default)->toBeTrue()
        ->and($coffee->variants->firstWhere('name', 'Besar')->is_default)->toBeFalse()
        ->and($coffee->modifierGroups->pluck('id')->all())->toBe([$this->sugar->id])
        ->and(AuditLog::query()->where('action', 'catalog.imported')->sole()->after)->toBe($result);
});

it('updates prices on a second import instead of duplicating the menu', function (): void {
    $import = app(ImportMenu::class);

    $import->commit($import->read(menuSheet([['Kopi', 'Latte', 'Reguler', '28000', 'LAT-R', '']])), false);
    $import->commit($import->read(menuSheet([
        ['kopi', 'Latte', 'Reguler', '30000', 'LAT-R', ''],
        ['Kopi', 'Latte', 'Besar', '35000', '', ''],
    ])), false);

    expect(Category::query()->count())->toBe(1)
        ->and(Product::query()->count())->toBe(1)
        ->and(ProductVariant::query()->where('sku', 'LAT-R')->sole()->base_price)->toBe(30_000)
        ->and(ProductVariant::query()->count())->toBe(2);
});

it('says which column is missing', function (): void {
    expect(fn () => app(ImportMenu::class)->read(menuSheet([['Kopi', 'Latte', '28000']], ['kategori', 'nama_produk', 'price'])))
        ->toThrow(RuntimeException::class, __('catalog.import.missing_column', ['column' => 'harga']));
});

it('turns away a file that is not a spreadsheet', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'bad');
    file_put_contents($path, "kategori,nama_produk,harga\nKopi,Latte,28000");

    expect(fn () => app(ImportMenu::class)->read($path))->toThrow(RuntimeException::class, __('catalog.import.not_xlsx'));
});

it('reads its own writer back cell for cell', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'rt');

    (new XlsxWriter)->sheet('Uji', ['a', 'b', 'c'], [XlsxWriter::TEXT, XlsxWriter::MONEY, XlsxWriter::DECIMAL], [
        ['Kopi & "Susu" <besar>', 25_000, 1.5],
        [null, 0, 2.25],
    ])->save($path);

    expect((new XlsxReader)->rows($path))->toBe([
        ['a', 'b', 'c'],
        ['Kopi & "Susu" <besar>', 25_000, 1.5],
        [null, 0, 2.25],
    ]);
});

it('previews and imports from the catalog page', function (): void {
    $path = menuSheet([
        ['Teh', 'Teh Tarik', '', '18000', '', ''],
        ['Teh', '', '', '18000', '', ''],
    ]);

    Livewire::test('pages::catalog.index')
        ->set('sheet', UploadedFile::fake()->createWithContent('menu.xlsx', (string) file_get_contents($path)))
        ->call('checkSheet')
        ->assertHasNoErrors()
        ->assertSee(__('catalog.import.error.product'))
        ->assertDontSee(__('catalog.import.commit', ['count' => 1]))
        ->call('importSheet', true)
        ->assertSet('preview', [])
        ->assertSee('Teh Tarik');

    expect(Product::query()->sole()->name)->toBe('Teh Tarik');
});
