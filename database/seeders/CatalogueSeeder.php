<?php

namespace Database\Seeders;

use App\Enums\AttributeInputType;
use App\Models\Category;
use App\Models\ProductAttribute;
use App\Models\Unit;
use App\Models\User;
use App\Services\CategoryTree;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

// php artisan db:seed --class=CatalogueSeeder

/**
 * A starting catalogue so the first shops can list products on day one (D32, D17): units of sale, a
 * category tree covering the five shop types, and the attributes that make the first filters (Colour,
 * Capacity, Gauge...). From then on it is the Admin's, on the Catalogue screens.
 *
 * Each part is seeded only into an empty table, so running this again never brings back what the
 * Admin removed or undoes what they renamed. docs/catalogue.md.
 */
class CatalogueSeeder extends Seeder
{
    /** name => [plural, takes decimals] */
    private const UNITS = [
        'piece' => ['pieces', false],
        'dozen' => ['dozens', false],
        'bale' => ['bales', false],
        'set' => ['sets', false],
        'pair' => ['pairs', false],
        'box' => ['boxes', false],
        'carton' => ['cartons', false],
        'kg' => ['kg', true],
        'litre' => ['litres', true],
        'metre' => ['metres', true],
        'bag' => ['bags', false],
        'roll' => ['rolls', false],
        'sheet' => ['sheets', false],
        'bunch' => ['bunches', false],
        'heap' => ['heaps', false],
        'tray' => ['trays', false],
        'crate' => ['crates', false],
    ];

    /** name => [type, unit, choices, hint] */
    private const ATTRIBUTES = [
        'Colour' => [AttributeInputType::List, null, ['Red', 'Blue', 'Green', 'Yellow', 'Orange', 'Pink', 'Purple', 'Black', 'White', 'Grey', 'Brown', 'Clear', 'Multicolour'], null],
        'Material' => [AttributeInputType::List, null, ['Plastic', 'Stainless steel', 'Aluminium', 'Glass', 'Ceramic', 'Melamine', 'Wood', 'Fabric', 'Leather'], null],
        'Capacity' => [AttributeInputType::Number, 'L', [], 'How much it holds, in litres.'],
        'Diameter' => [AttributeInputType::Number, 'cm', [], 'Across the top, in centimetres.'],
        'Brand' => [AttributeInputType::Text, null, [], 'As printed on it. Leave empty if it has none.'],
        'Gauge' => [AttributeInputType::List, null, ['26', '28', '30', '32'], 'Smaller is thicker.'],
        'Length' => [AttributeInputType::Number, 'm', [], 'In metres.'],
        'Weight' => [AttributeInputType::Number, 'kg', [], 'Of one unit, in kilograms.'],
        'Clothing size' => [AttributeInputType::List, null, ['XS', 'S', 'M', 'L', 'XL', 'XXL', 'XXXL'], null],
        'Shoe size' => [AttributeInputType::List, null, ['35', '36', '37', '38', '39', '40', '41', '42', '43', '44', '45', '46'], 'EU size.'],
        'Storage' => [AttributeInputType::List, null, ['16 GB', '32 GB', '64 GB', '128 GB', '256 GB', '512 GB', '1 TB'], null],
        'Variety' => [AttributeInputType::Text, null, [], 'Like Gonja, Bogoya, Irish or Ndiizi.'],
    ];

    /**
     * name => [icon, attributes (name => required), children]. Children take the same shape, or a
     * plain list of names.
     *
     * @var array<string, array{0: string|null, 1: array<string, bool>, 2: array<mixed>}>
     */
    private const TREE = [
        'Household & kitchen' => ['pi-home', ['Colour' => false, 'Material' => false], [
            'Kitchen' => [null, [], [
                'Basins' => [null, ['Capacity' => false, 'Diameter' => false], []],
                'Buckets & pails' => [null, ['Capacity' => false], []],
                'Jerrycans' => [null, ['Capacity' => true], []],
                'Cups & mugs' => [null, [], []],
                'Plates & bowls' => [null, [], []],
                'Food containers' => [null, ['Capacity' => false], []],
                'Flasks' => [null, ['Capacity' => false], []],
                'Cooking pots & pans' => [null, ['Diameter' => false], []],
            ]],
            'Bathroom & laundry' => [null, [], ['Baby baths', 'Laundry baskets', 'Soap dishes & racks']],
            'Storage' => [null, [], ['Storage boxes', 'Drawers & racks']],
            'Plastic furniture' => [null, [], ['Chairs', 'Tables', 'Stools']],
            'Cleaning' => [null, [], ['Brooms & mops', 'Dustbins']],
        ]],
        'Hardware & building' => ['pi-wrench', ['Brand' => false], [
            'Roofing' => [null, [], [
                'Iron sheets' => [null, ['Gauge' => true, 'Length' => true, 'Colour' => false], []],
                'Nails & screws' => [null, [], []],
            ]],
            'Plumbing' => [null, [], [
                'Pipes' => [null, ['Length' => false, 'Diameter' => false], []],
                'Taps & fittings' => [null, [], []],
                'Water tanks' => [null, ['Capacity' => true], []],
            ]],
            'Cement & aggregates' => [null, ['Weight' => false], []],
            'Paint' => [null, ['Colour' => false, 'Capacity' => false], []],
            'Tools' => [null, [], []],
            'Electrical' => [null, [], []],
        ]],
        'Groceries & fresh food' => ['pi-shopping-cart', [], [
            'Fruits' => [null, ['Variety' => false], []],
            'Vegetables' => [null, ['Variety' => false], []],
            'Grains & flour' => [null, ['Weight' => false], []],
            'Cooking oil' => [null, ['Capacity' => false, 'Brand' => false], []],
            'Drinks' => [null, ['Brand' => false], []],
            'Snacks' => [null, ['Brand' => false], []],
        ]],
        'Fashion & beauty' => ['pi-star', ['Colour' => false, 'Brand' => false], [
            'Women\'s clothing' => [null, ['Clothing size' => false], []],
            'Men\'s clothing' => [null, ['Clothing size' => false], []],
            'Shoes' => [null, ['Shoe size' => false], []],
            'Bags' => [null, ['Material' => false], []],
            'Beauty & personal care' => [null, [], []],
            'Hair' => [null, [], []],
        ]],
        'Electronics & phones' => ['pi-mobile', ['Brand' => false], [
            'Phones' => [null, ['Storage' => false, 'Colour' => false], []],
            'Phone accessories' => [null, ['Colour' => false], []],
            'TVs & audio' => [null, [], []],
            'Small appliances' => [null, [], []],
            'Computers' => [null, ['Storage' => false], []],
        ]],
        'Babies & kids' => ['pi-heart', [], ['Toys', 'Baby care', 'School supplies']],
    ];

    public function run(): void
    {
        $by = User::role('Admin')->orderBy('created_at')->first();

        DB::transaction(function () use ($by) {
            $this->units($by);
            $attributes = $this->attributes($by);
            $this->tree($by, $attributes);
        });
    }

    private function units(?User $by): void
    {
        if (Unit::query()->exists()) {
            $this->command?->info('Units: kept as they are.');

            return;
        }
        $position = 0;
        foreach (self::UNITS as $name => [$plural, $decimals]) {
            Unit::query()->create(['name' => $name, 'plural' => $plural, 'allows_decimals' => $decimals, 'position' => $position++, 'created_by' => $by?->id, 'updated_by' => $by?->id]);
        }
        $this->command?->info('Units: '.count(self::UNITS).' added.');
    }

    /** @return array<string, string> name => id */
    private function attributes(?User $by): array
    {
        if (ProductAttribute::query()->doesntExist()) {
            foreach (self::ATTRIBUTES as $name => [$type, $unit, $choices, $hint]) {
                $attribute = ProductAttribute::query()->create(['name' => $name, 'input_type' => $type, 'unit' => $unit, 'hint' => $hint, 'created_by' => $by?->id, 'updated_by' => $by?->id]);
                foreach ($choices as $position => $value) {
                    $attribute->options()->create(['value' => $value, 'position' => $position]);
                }
            }
            $this->command?->info('Attributes: '.count(self::ATTRIBUTES).' added.');
        }

        return ProductAttribute::query()->pluck('id', 'name')->all();
    }

    /** @param  array<string, string>  $attributes */
    private function tree(?User $by, array $attributes): void
    {
        if (Category::query()->exists()) {
            $this->command?->info('Categories: kept as they are.');

            return;
        }

        $tree = app(CategoryTree::class);
        $count = 0;
        $add = function (array $nodes, ?string $parentId) use (&$add, $tree, $by, $attributes, &$count) {
            $position = 0;
            foreach ($nodes as $key => $node) {
                [$name, $icon, $own, $children] = is_int($key) ? [$node, null, [], []] : [$key, ...$node];
                $category = Category::query()->create([
                    'parent_id' => $parentId,
                    'name' => $name,
                    'slug' => $tree->uniqueSlug($name, $parentId, null),
                    'icon' => $icon,
                    'position' => $position++,
                    'created_by' => $by?->id,
                    'updated_by' => $by?->id,
                ]);
                $count++;
                $category->productAttributes()->sync(collect($own)->keys()->mapWithKeys(fn (string $a, int $i) => [
                    $attributes[$a] => ['position' => $i, 'is_required' => $own[$a]],
                ])->all());
                $add($children, $category->id);
            }
        };
        $add(self::TREE, null);

        $this->command?->info("Categories: {$count} added.");
    }
}
