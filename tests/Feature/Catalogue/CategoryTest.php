<?php

/**
 * The marketplace's category tree (D32): kept by the Admin, read by vendors. A name once among its
 * siblings, never inside itself, at most four deep; attributes flow down to children.
 */

use App\Models\Category;
use App\Models\Product;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('builds the tree and reads it back flat, each under its parent, with paths', function () {
    actingAsAdmin();

    $household = $this->postJson('/api/v1/categories', ['name' => 'Household', 'icon' => 'pi-home'])->assertCreated()->json('data');
    $kitchen = $this->postJson('/api/v1/categories', ['name' => 'Kitchen', 'parent_id' => $household['id']])->assertCreated()->json('data');
    $this->postJson('/api/v1/categories', ['name' => 'Basins', 'parent_id' => $kitchen['id']])->assertCreated()
        ->assertJsonPath('data.path', 'Household › Kitchen › Basins')
        ->assertJsonPath('data.depth', 3)
        ->assertJsonPath('data.slug', 'basins');
    $this->postJson('/api/v1/categories', ['name' => 'Garden'])->assertCreated();

    $this->getJson('/api/v1/categories')->assertOk()
        ->assertJsonPath('data.*.name', ['Household', 'Kitchen', 'Basins', 'Garden']);
});

it('keeps names unique among siblings only, and slugs unique across the tree', function () {
    actingAsAdmin();
    $fashion = Category::factory()->create(['name' => 'Fashion', 'slug' => 'fashion']);
    $phones = Category::factory()->create(['name' => 'Phones', 'slug' => 'phones']);
    $this->postJson('/api/v1/categories', ['name' => 'Accessories', 'parent_id' => $fashion->id])->assertCreated()->assertJsonPath('data.slug', 'accessories');

    $this->postJson('/api/v1/categories', ['name' => 'accessories', 'parent_id' => $fashion->id])
        ->assertUnprocessable()->assertJsonValidationErrors('name');
    $this->postJson('/api/v1/categories', ['name' => 'Accessories', 'parent_id' => $phones->id])
        ->assertCreated()->assertJsonPath('data.slug', 'phones-accessories');
});

it('never puts a category inside itself, and stops at four levels', function () {
    actingAsAdmin();
    $a = Category::factory()->create();
    $b = Category::factory()->under($a)->create();
    $c = Category::factory()->under($b)->create();
    $d = Category::factory()->under($c)->create();

    $this->putJson("/api/v1/categories/{$a->id}", ['parent_id' => $c->id])->assertUnprocessable()->assertJsonValidationErrors('parent_id');
    $this->postJson('/api/v1/categories', ['name' => 'Fifth', 'parent_id' => $d->id])->assertUnprocessable()->assertJsonValidationErrors('parent_id');

    // Moving b (with c and d under it) under a new top category would make five levels.
    $top = Category::factory()->create();
    $this->putJson("/api/v1/categories/{$b->id}", ['parent_id' => Category::factory()->under($top)->create()->id])
        ->assertUnprocessable()->assertJsonValidationErrors('parent_id');
});

it('deletes only an empty, unused category; others are turned off', function () {
    actingAsAdmin();
    $parent = Category::factory()->create();
    $child = Category::factory()->under($parent)->create();
    Product::factory()->create(['category_id' => $child->id]);

    $this->deleteJson("/api/v1/categories/{$parent->id}")->assertUnprocessable();
    $this->deleteJson("/api/v1/categories/{$child->id}")->assertUnprocessable()
        ->assertJsonPath('message', 'Products are in this category. Turn it off instead, so they keep their place until moved.');

    $this->putJson("/api/v1/categories/{$child->id}", ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
    $this->deleteJson('/api/v1/categories/'.Category::factory()->create()->id)->assertOk();
});

it('gives children their parents\' attributes, and lists only categories that are on for pickers', function () {
    actingAsAdmin();
    $fx = catalogueFixture();

    $this->getJson("/api/v1/categories/{$fx['basins']->id}")->assertOk()
        ->assertJsonPath('data.attributes.*.name', ['Capacity'])
        ->assertJsonPath('data.effective_attributes.*.name', ['Colour', 'Capacity'])
        ->assertJsonPath('data.effective_attributes.1.is_required', true);

    // Colour already comes from Household.
    $this->putJson("/api/v1/categories/{$fx['basins']->id}/attributes", ['attributes' => [['id' => $fx['colour']->id]]])
        ->assertUnprocessable()->assertJsonValidationErrors('attributes.0.id');

    $fx['household']->update(['is_active' => false]);
    $names = collect($this->getJson('/api/v1/categories?active=1')->json('data'))->pluck('name');
    expect($names)->not->toContain('Household')->not->toContain('Basins');
});

it('lets vendors read the tree but not change it', function () {
    [$shop] = signedInShopOwner();
    $category = Category::factory()->create();

    $this->getJson('/api/v1/categories')->assertOk();
    $this->postJson('/api/v1/categories', ['name' => 'Mine'])->assertForbidden();
    $this->putJson("/api/v1/categories/{$category->id}", ['name' => 'Renamed'])->assertForbidden();
});
