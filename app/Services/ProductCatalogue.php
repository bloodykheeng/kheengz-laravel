<?php

namespace App\Services;

use App\Enums\AttributeInputType;
use App\Enums\ProductStatus;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeOption;
use App\Models\ProductOption;
use App\Models\ProductOptionValue;
use App\Models\ProductPhoto;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Saves a product whole, the way the product form sends it: its details, its category's attributes,
 * its own options, every variant with its price breaks, and its shop sections. One transaction, so a
 * product is never left half-saved; an Active product must still be fit for sale afterwards, or
 * nothing is saved. Ids survive edits (an option renamed from "colour" to "Colour" is the same row),
 * because stock and orders will point at variants. docs/catalogue.md.
 */
class ProductCatalogue
{
    /**
     * @param  array<string, mixed>  $data  validated by ProductController::rules()
     */
    public function save(Shop $shop, ?Product $product, array $data, User $by): Product
    {
        $category = Category::query()->findOrFail($data['category_id']);
        if (! $category->is_active && $product?->category_id !== $category->id) {
            $this->fail('category_id', "{$category->name} is turned off. Choose another category.");
        }

        $attributes = $category->effectiveAttributes()->keyBy('id');
        $units = Unit::query()->whereIn('id', collect($data['variants'])->pluck('unit_id'))->get()->keyBy('id');
        $this->checkVariants($shop, $product, $data, $units);
        $attributeValues = $this->checkAttributes($data['attributes'] ?? [], $attributes);

        return DB::transaction(function () use ($shop, $product, $data, $by, $attributes, $attributeValues) {
            $product ??= new Product(['shop_id' => $shop->id, 'status' => ProductStatus::Draft, 'created_by' => $by->id]);
            $product->fill([
                'category_id' => $data['category_id'],
                'name' => $data['name'],
                'slug' => $this->uniqueSlug($shop, $data['slug'] ?? null ?: $data['name'], $product->exists ? $product->id : null),
                'description' => $data['description'] ?? null,
                'collect_only' => (bool) ($data['collect_only'] ?? false),
                'updated_by' => $by->id,
            ])->save();

            $product->sections()->sync($data['section_ids'] ?? []);
            $this->syncAttributeValues($product, $attributeValues);
            [$valueIds, $keptOptions, $keptValues] = $this->syncOptions($product, $data['options'] ?? [], $attributes);
            $this->syncVariants($product, $data['variants'], $valueIds);
            // Last: a removed value may have been used by a variant that syncVariants just removed.
            $this->removeUnusedOptions($product, $keptOptions, $keptValues);

            if ($product->status === ProductStatus::Active) {
                $this->assertFitForSale($product->refresh());
            }

            return $product;
        });
    }

    /**
     * A product and everything that is only its own: photos (files too), variants, price breaks,
     * options, details, section places. The caller decides whether it may go (Product::isDeletable).
     */
    public function delete(Product $product): void
    {
        DB::transaction(function () use ($product) {
            $product->photos()->get()->each->delete(); // each, so the files go too
            $variantIds = $product->variants()->pluck('id');
            DB::table('price_breaks')->whereIn('product_variant_id', $variantIds)->delete();
            DB::table('product_variant_option_value')->whereIn('product_variant_id', $variantIds)->delete();
            $product->variants()->delete();
            $optionIds = $product->options()->pluck('id');
            DB::table('product_option_values')->whereIn('product_option_id', $optionIds)->delete();
            $product->options()->delete();
            $product->attributeValues()->delete();
            $product->sections()->detach();
            $product->delete();
        });
    }

    /**
     * What stops a product going on sale, as field => message, for the form to show where to act.
     *
     * @return array<string, string>
     */
    public function problemsForSale(Product $product): array
    {
        if ($product->isBlocked()) {
            return ['status' => "Kheengz took this product down: {$product->blocked_reason} Fix it and ask support to allow it again."];
        }

        $problems = [];
        $category = $product->category;

        if (! $category->is_active) {
            $problems['category_id'] = "{$category->name} is turned off. Choose another category.";
        }
        if ($product->photos()->doesntExist()) {
            $problems['photos'] = 'Add at least one photo: buyers do not buy what they cannot see.';
        }
        if ($product->variants()->where('is_active', true)->doesntExist()) {
            $problems['variants'] = 'Offer at least one variant.';
        }

        $filled = $product->attributeValues()->pluck('product_attribute_id')->all();
        $missing = $category->effectiveAttributes()
            ->filter(fn (ProductAttribute $a) => (bool) $a->getAttribute('is_required') && ! in_array($a->id, $filled, true))
            ->pluck('name');
        if ($missing->isNotEmpty()) {
            $problems['attributes'] = 'Fill in '.$missing->implode(', ').': buyers filter by '.($missing->count() === 1 ? 'it' : 'them').'.';
        }

        return $problems;
    }

    public function assertFitForSale(Product $product): void
    {
        $problems = $this->problemsForSale($product);
        if ($problems !== []) {
            throw ValidationException::withMessages(array_map(fn (string $m) => [$m], $problems));
        }
    }

    /**
     * Each variant has one value of each option, no two variants are the same, prices make sense,
     * price breaks step down, and SKUs are the shop's own.
     *
     * @param  array<string, mixed>  $data
     * @param  Collection<string, Unit>  $units
     */
    private function checkVariants(Shop $shop, ?Product $product, array $data, Collection $units): void
    {
        $options = collect($data['options'] ?? []);
        $variants = collect($data['variants']);

        if ($options->isEmpty() && $variants->count() > 1) {
            $this->fail('variants', 'Add an option (like Size or Colour) to sell more than one variant.');
        }

        $seen = [];
        foreach ($variants as $i => $variant) {
            $values = array_values($variant['option_values'] ?? []);
            if (count($values) !== $options->count()) {
                $this->fail("variants.{$i}.option_values", 'Choose one value of each option for this variant.');
            }
            foreach ($values as $o => $value) {
                $allowed = collect($options[$o]['values'])->map(fn ($v) => mb_strtolower(trim($v)));
                if (! $allowed->contains(mb_strtolower(trim((string) $value)))) {
                    $this->fail("variants.{$i}.option_values", "\"{$value}\" is not one of the {$options[$o]['name']} values.");
                }
            }
            $key = mb_strtolower(implode("\u{1F}", array_map('trim', $values)));
            if (isset($seen[$key])) {
                $this->fail("variants.{$i}.option_values", 'Two variants have the same values. Remove one.');
            }
            $seen[$key] = true;

            if (isset($variant['compare_at_price']) && $variant['compare_at_price'] <= $variant['price']) {
                $this->fail("variants.{$i}.compare_at_price", 'The was-price must be above the price, or left empty.');
            }

            $unit = $units[$variant['unit_id']] ?? null;
            $this->checkPriceBreaks($i, $variant, $unit);
        }

        $skus = $variants->pluck('sku')->filter()->map(fn ($s) => mb_strtolower(trim($s)));
        if ($skus->isNotEmpty()) {
            $taken = ProductVariant::query()
                ->join('products', 'products.id', '=', 'product_variants.product_id')
                ->where('products.shop_id', $shop->id)
                ->when($product, fn ($q) => $q->where('products.id', '!=', $product->id))
                ->whereIn(DB::raw('lower(product_variants.sku)'), $skus->all())
                ->pluck('product_variants.sku')
                ->map(fn ($s) => mb_strtolower((string) $s));
            foreach ($variants as $i => $variant) {
                if (! empty($variant['sku']) && $taken->contains(mb_strtolower(trim($variant['sku'])))) {
                    $this->fail("variants.{$i}.sku", "Another of your products already uses SKU {$variant['sku']}.");
                }
            }
        }
    }

    /** @param  array<string, mixed>  $variant */
    private function checkPriceBreaks(int $i, array $variant, ?Unit $unit): void
    {
        $breaks = collect($variant['price_breaks'] ?? [])->sortBy(fn ($b) => (float) $b['min_quantity'])->values();
        $last = (int) $variant['price'];
        $lastQuantity = null;

        foreach ($breaks as $b => $break) {
            $quantity = (float) $break['min_quantity'];
            if ($unit && ! $unit->allows_decimals && floor($quantity) !== $quantity) {
                $this->fail("variants.{$i}.price_breaks", "Use whole numbers of {$unit->plural}.");
            }
            if ($lastQuantity !== null && $quantity === $lastQuantity) {
                $this->fail("variants.{$i}.price_breaks", 'Two price breaks start at the same quantity.');
            }
            if ((int) $break['unit_price'] >= $last) {
                $this->fail("variants.{$i}.price_breaks", 'Each price break must cost less than the one before it, and less than the price.');
            }
            $last = (int) $break['unit_price'];
            $lastQuantity = $quantity;
        }
    }

    /**
     * Only the category's attributes, each filled the way it is entered. Empty ones are dropped.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  Collection<string, ProductAttribute>  $attributes
     * @return list<array{product_attribute_id: string, product_attribute_option_id: ?string, value_number: ?string, value_text: ?string}>
     */
    private function checkAttributes(array $rows, Collection $attributes): array
    {
        $out = [];
        foreach ($rows as $i => $row) {
            $attribute = $attributes[$row['attribute_id']] ?? null;
            if ($attribute === null) {
                $this->fail("attributes.{$i}.attribute_id", 'That detail does not belong to this category.');
            }

            $value = ['product_attribute_id' => $attribute->id, 'product_attribute_option_id' => null, 'value_number' => null, 'value_text' => null];
            switch ($attribute->input_type) {
                case AttributeInputType::List:
                    if (empty($row['option_id'])) {
                        continue 2;
                    }
                    if (! $attribute->options->contains('id', $row['option_id'])) {
                        $this->fail("attributes.{$i}.option_id", "Choose one of the {$attribute->name} choices.");
                    }
                    $value['product_attribute_option_id'] = $row['option_id'];
                    break;
                case AttributeInputType::Number:
                    if (! isset($row['number']) || $row['number'] === '') {
                        continue 2;
                    }
                    $value['value_number'] = (string) $row['number'];
                    break;
                case AttributeInputType::Text:
                    if (trim((string) ($row['text'] ?? '')) === '') {
                        continue 2;
                    }
                    $value['value_text'] = trim((string) $row['text']);
                    break;
            }
            $out[] = $value;
        }

        return $out;
    }

    /** @param  list<array{product_attribute_id: string, product_attribute_option_id: ?string, value_number: ?string, value_text: ?string}>  $values */
    private function syncAttributeValues(Product $product, array $values): void
    {
        $keep = array_column($values, 'product_attribute_id');
        $product->attributeValues()->whereNotIn('product_attribute_id', $keep)->delete();

        foreach ($values as $value) {
            $product->attributeValues()->updateOrCreate(['product_attribute_id' => $value['product_attribute_id']], $value);
        }
    }

    /**
     * Options and their values, matched to what is there by name (ignoring case), so their ids live on.
     * An option named like one of the category's List attributes feeds that filter, and its values the
     * matching choices.
     *
     * @param  list<array{name: string, values: list<string>}>  $options
     * @param  Collection<string, ProductAttribute>  $attributes
     * @return array{0: list<array<string, string>>, 1: list<string>, 2: list<string>} per option its
     *                                                                                 lower-cased value => value id; the option ids kept; the value ids kept
     */
    private function syncOptions(Product $product, array $options, Collection $attributes): array
    {
        $existing = $product->options()->with('values')->get()->keyBy(fn (ProductOption $o) => mb_strtolower($o->name));
        $listAttributes = $attributes->filter(fn (ProductAttribute $a) => $a->input_type === AttributeInputType::List)
            ->keyBy(fn (ProductAttribute $a) => mb_strtolower($a->name));

        $ids = [];
        $keptOptions = [];
        $keptValues = [];
        foreach (array_values($options) as $position => $row) {
            $name = trim($row['name']);
            $attribute = $listAttributes[mb_strtolower($name)] ?? null;
            $option = $existing[mb_strtolower($name)] ?? new ProductOption(['product_id' => $product->id]);
            $option->fill(['name' => $name, 'position' => $position, 'product_attribute_id' => $attribute?->id])->save();
            $keptOptions[] = $option->id;

            $values = $option->exists ? $option->values()->get()->keyBy(fn (ProductOptionValue $v) => mb_strtolower($v->value)) : collect();
            $choices = $attribute ? $attribute->options->keyBy(fn (ProductAttributeOption $c) => mb_strtolower($c->value)) : collect();
            $ids[$position] = [];
            foreach (array_values($row['values']) as $v => $text) {
                $text = trim($text);
                $key = mb_strtolower($text);
                $value = $values[$key] ?? new ProductOptionValue(['product_option_id' => $option->id]);
                $value->fill(['value' => $text, 'position' => $v, 'product_attribute_option_id' => $choices[$key]->id ?? null])->save();
                $ids[$position][$key] = $value->id;
                $keptValues[] = $value->id;
            }
        }

        return [$ids, $keptOptions, $keptValues];
    }

    /**
     * Variants matched by id, or else by their values, so a variant that keeps its values keeps its id
     * (and later its stock). Variants no longer listed are removed, then the options and values only
     * they used.
     *
     * @param  list<array<string, mixed>>  $variants
     * @param  list<array<string, string>>  $valueIds
     */
    private function syncVariants(Product $product, array $variants, array $valueIds): void
    {
        $existing = $product->variants()->with('optionValues')->get();
        $byId = $existing->keyBy('id');
        $byValues = $existing->keyBy(fn (ProductVariant $v) => $v->optionValues->pluck('id')->sort()->implode(','));

        $kept = [];
        foreach (array_values($variants) as $position => $row) {
            $ids = collect(array_values($row['option_values'] ?? []))
                ->map(fn ($text, $o) => $valueIds[$o][mb_strtolower(trim((string) $text))])
                ->all();
            $key = collect($ids)->sort()->implode(',');

            $variant = (! empty($row['id']) ? $byId[$row['id']] ?? null : null) ?? $byValues[$key] ?? null;
            if (! empty($row['id']) && ! $byId->has($row['id'])) {
                $this->fail("variants.{$position}.id", 'That variant is not part of this product.');
            }
            $variant ??= new ProductVariant(['product_id' => $product->id]);
            if (in_array($variant->id, $kept, true)) {
                $variant = new ProductVariant(['product_id' => $product->id]);
            }

            $variant->fill([
                'sku' => isset($row['sku']) && trim($row['sku']) !== '' ? trim($row['sku']) : null,
                'barcode' => isset($row['barcode']) && trim($row['barcode']) !== '' ? trim($row['barcode']) : null,
                'unit_id' => $row['unit_id'],
                'price' => (int) $row['price'],
                'compare_at_price' => isset($row['compare_at_price']) ? (int) $row['compare_at_price'] : null,
                'weight_grams' => isset($row['weight_grams']) ? (int) $row['weight_grams'] : null,
                'position' => $position,
                'is_active' => (bool) ($row['is_active'] ?? true),
            ])->save();
            $variant->optionValues()->sync($ids);

            $variant->priceBreaks()->delete();
            foreach ($row['price_breaks'] ?? [] as $break) {
                $variant->priceBreaks()->create(['min_quantity' => $break['min_quantity'], 'unit_price' => (int) $break['unit_price']]);
            }
            $kept[] = $variant->id;
        }

        $gone = $existing->whereNotIn('id', $kept);
        if ($gone->isNotEmpty()) {
            $goneIds = $gone->pluck('id')->all();
            ProductPhoto::query()->whereIn('product_variant_id', $goneIds)->update(['product_variant_id' => null]);
            DB::table('product_variant_option_value')->whereIn('product_variant_id', $goneIds)->delete();
            DB::table('price_breaks')->whereIn('product_variant_id', $goneIds)->delete();
            ProductVariant::query()->whereIn('id', $goneIds)->delete();
        }
    }

    /**
     * @param  list<string>  $keptOptions
     * @param  list<string>  $keptValues
     */
    private function removeUnusedOptions(Product $product, array $keptOptions, array $keptValues): void
    {
        $optionIds = $product->options()->pluck('id');
        ProductOptionValue::query()->whereIn('product_option_id', $optionIds)->whereNotIn('id', $keptValues)->delete();
        ProductOption::query()->whereIn('id', $optionIds)->whereNotIn('id', $keptOptions)->delete();
    }

    /** "20 Litre Basin" → 20-litre-basin, then -2, -3 if the shop already has it. */
    private function uniqueSlug(Shop $shop, string $from, ?string $ignore): string
    {
        $base = Str::limit(Str::slug($from), 160, '') ?: 'product';
        $slug = $base;
        for ($n = 2; $shop->products()->where('slug', $slug)->when($ignore, fn ($q) => $q->whereKeyNot($ignore))->exists(); $n++) {
            $slug = "{$base}-{$n}";
        }

        return $slug;
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }
}
