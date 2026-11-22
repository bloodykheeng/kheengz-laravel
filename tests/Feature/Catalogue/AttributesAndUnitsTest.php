<?php

/**
 * Attributes (what filters are made of) and units of sale: the Admin's lists. What products already
 * use cannot be pulled from under them.
 */

use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Models\ProductVariant;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('defines a list attribute with its choices, and renames a choice in place', function () {
    actingAsAdmin();

    $attribute = $this->postJson('/api/v1/product-attributes', [
        'name' => 'Material',
        'input_type' => 'List',
        'options' => [['value' => 'Plastic'], ['value' => 'Steel']],
    ])->assertCreated()->assertJsonPath('data.options.*.value', ['Plastic', 'Steel'])->json('data');

    $steel = $attribute['options'][1]['id'];
    $this->putJson("/api/v1/product-attributes/{$attribute['id']}", [
        'options' => [['id' => $steel, 'value' => 'Stainless steel'], ['id' => $attribute['options'][0]['id'], 'value' => 'Plastic']],
    ])->assertOk()
        ->assertJsonPath('data.options.0.id', $steel)
        ->assertJsonPath('data.options.0.value', 'Stainless steel');

    $this->postJson('/api/v1/product-attributes', ['name' => 'Material', 'input_type' => 'Text'])->assertUnprocessable()->assertJsonValidationErrors('name');
    $this->postJson('/api/v1/product-attributes', ['name' => 'Finish', 'input_type' => 'List'])->assertUnprocessable()->assertJsonValidationErrors('options');
});

it('keeps a choice and a type that products use', function () {
    actingAsAdmin();
    $fx = catalogueFixture();
    $red = $fx['colour']->options()->where('value', 'Red')->first();
    ProductAttributeValue::query()->create([
        'product_id' => Product::factory()->create(['category_id' => $fx['basins']->id])->id,
        'product_attribute_id' => $fx['colour']->id,
        'product_attribute_option_id' => $red->id,
    ]);

    $this->putJson("/api/v1/product-attributes/{$fx['colour']->id}", ['options' => [['value' => 'Blue']]])
        ->assertUnprocessable()->assertJsonPath('errors.options.0', 'Products use "Red", so it stays.');
    $this->putJson("/api/v1/product-attributes/{$fx['colour']->id}", ['input_type' => 'Text'])
        ->assertUnprocessable()->assertJsonValidationErrors('input_type');
    $this->deleteJson("/api/v1/product-attributes/{$fx['colour']->id}")->assertUnprocessable();
    $this->deleteJson("/api/v1/product-attributes/{$fx['capacity']->id}")->assertOk();
    expect($fx['basins']->productAttributes()->count())->toBe(0);
});

it('offers a shop type its own units first', function () {
    actingAsAdmin();
    foreach (['piece' => false, 'dozen' => false, 'kg' => true, 'bunch' => false, 'metre' => true] as $name => $decimals) {
        $this->postJson('/api/v1/units', ['name' => $name, 'plural' => $name.'s', 'allows_decimals' => $decimals])->assertCreated();
    }

    $groceries = $this->getJson('/api/v1/units?shop_type='.urlencode('Groceries & fresh produce'))->assertOk()->json('data');
    expect(collect($groceries)->take(3)->pluck('name')->all())->toBe(['kg', 'bunch', 'piece'])
        ->and(collect($groceries)->firstWhere('name', 'metre')['suggested'])->toBeFalse();
});

it('keeps whether a unit takes decimals once products are sold by it', function () {
    actingAsAdmin();
    $fx = catalogueFixture();
    ProductVariant::query()->create(['product_id' => Product::factory()->create()->id, 'unit_id' => $fx['piece']->id, 'price' => 1000]);

    $this->putJson("/api/v1/units/{$fx['piece']->id}", ['allows_decimals' => true])->assertUnprocessable();
    $this->putJson("/api/v1/units/{$fx['piece']->id}", ['plural' => 'pcs'])->assertOk();
    $this->deleteJson("/api/v1/units/{$fx['piece']->id}")->assertUnprocessable();
    $this->deleteJson("/api/v1/units/{$fx['kg']->id}")->assertOk();
});

it('deletes ticked attributes and units all or none', function () {
    actingAsAdmin();
    $fx = catalogueFixture();
    ProductVariant::query()->create(['product_id' => Product::factory()->create()->id, 'unit_id' => $fx['piece']->id, 'price' => 1000]);
    $finish = $this->postJson('/api/v1/product-attributes', ['name' => 'Finish', 'input_type' => 'Text'])->json('data.id');

    $this->deleteJson('/api/v1/units', ['ids' => [$fx['piece']->id, $fx['kg']->id]])->assertUnprocessable()
        ->assertJsonPath('message', 'Products are sold by the piece, so nothing was deleted. Untick it, or turn it off instead.');
    $this->deleteJson('/api/v1/units', ['ids' => [$fx['kg']->id]])->assertOk();

    $this->deleteJson('/api/v1/product-attributes', ['ids' => [$fx['capacity']->id, $finish]])->assertOk()->assertJsonPath('message', '2 attributes deleted.');
    $this->getJson('/api/v1/units?page=1&per_page=5')->assertOk()->assertJsonPath('meta.total', 1);
});
