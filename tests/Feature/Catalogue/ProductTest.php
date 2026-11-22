<?php

/**
 * Products (D32, D17): saved whole from the product form, with options, variants, price breaks,
 * attributes and sections; put on sale only when fit for it; hidden, not deleted, once sold.
 */

use App\Enums\ShopStatus;
use App\Models\Product;
use App\Models\ProductOptionValue;
use App\Models\ProductPhoto;
use App\Models\Shop;
use App\Models\User;
use App\Notifications\ProductBlocked;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
});

/**
 * A 20-litre basin in Red and Blue, 10 L and 20 L: four variants, a dozen price on one.
 *
 * @param  array<string, mixed>  $fx  catalogueFixture()
 * @return array<string, mixed>
 */
function basin(array $fx, array $overrides = []): array
{
    $variant = fn (string $colour, string $size, int $price, array $extra = []) => [
        'option_values' => [$colour, $size],
        'unit_id' => $fx['piece']->id,
        'price' => $price,
        ...$extra,
    ];

    return [
        'name' => 'Round Basin',
        'category_id' => $fx['basins']->id,
        'description' => 'Strong plastic basin for washing and storage.',
        'attributes' => [['attribute_id' => $fx['capacity']->id, 'number' => 20]],
        'options' => [
            ['name' => 'colour', 'values' => ['Red', 'Blue']],
            ['name' => 'Size', 'values' => ['10 L', '20 L']],
        ],
        'variants' => [
            $variant('Red', '10 L', 4000, ['sku' => 'BAS-R10']),
            $variant('Red', '20 L', 6000, ['sku' => 'BAS-R20', 'compare_at_price' => 7000, 'price_breaks' => [['min_quantity' => 12, 'unit_price' => 5500], ['min_quantity' => 60, 'unit_price' => 5000]]]),
            $variant('Blue', '10 L', 4000),
            $variant('Blue', '20 L', 6000),
        ],
        ...$overrides,
    ];
}

it('saves a product whole, its options feeding the filters they match', function () {
    [$shop] = signedInShopOwner();
    $fx = catalogueFixture();
    $section = $shop->sections()->create(['name' => 'Kitchen', 'slug' => 'kitchen']);

    $response = $this->postJson("/api/v1/shops/{$shop->id}/products", basin($fx, ['section_ids' => [$section->id]]))
        ->assertCreated()
        ->assertJsonPath('data.status', 'Draft')
        ->assertJsonPath('data.slug', 'round-basin')
        ->assertJsonPath('data.category.path', 'Household › Kitchen › Basins')
        ->assertJsonPath('data.variants.1.label', 'Red / 20 L')
        ->assertJsonPath('data.variants.1.price_breaks.0', ['min_quantity' => 12, 'unit_price' => 5500])
        ->assertJsonPath('data.attributes.0.display', '20 L')
        ->assertJsonPath('data.section_ids', [$section->id]);

    // "colour" is the category's Colour (case aside): its values count as Colour's choices.
    expect($response->json('data.options.0.attribute_id'))->toBe($fx['colour']->id)
        ->and(ProductOptionValue::query()->where('value', 'Red')->first()->product_attribute_option_id)
        ->toBe($fx['colour']->options()->where('value', 'Red')->value('id'));

    // A second product of the same name gets its own address.
    $this->postJson("/api/v1/shops/{$shop->id}/products", basin($fx, ['variants' => [['option_values' => ['Red', '10 L'], 'unit_id' => $fx['piece']->id, 'price' => 1]]]))
        ->assertCreated()->assertJsonPath('data.slug', 'round-basin-2');
});

it('keeps variant ids when the product is edited, and drops the variants taken out', function () {
    [$shop] = signedInShopOwner();
    $fx = catalogueFixture();
    $created = $this->postJson("/api/v1/shops/{$shop->id}/products", basin($fx))->json('data');
    $red20 = $created['variants'][1]['id'];

    $payload = basin($fx);
    $payload['options'][0]['values'] = ['Red', 'Green'];
    $payload['variants'] = [
        [...$payload['variants'][1], 'price' => 6500],
        ['option_values' => ['Green', '20 L'], 'unit_id' => $fx['piece']->id, 'price' => 6000],
    ];

    $this->putJson("/api/v1/products/{$created['id']}", $payload)->assertOk()
        ->assertJsonPath('data.variants.0.id', $red20)
        ->assertJsonPath('data.variants.0.price', 6500)
        ->assertJsonPath('data.variants.1.label', 'Green / 20 L')
        ->assertJsonPath('data.options.0.values', ['Red', 'Green']);
    expect(ProductOptionValue::query()->where('value', 'Blue')->exists())->toBeFalse();
});

it('refuses variants and prices that make no sense', function (array $change, string $field) {
    [$shop] = signedInShopOwner();
    $fx = catalogueFixture();
    $payload = basin($fx);
    data_set($payload, $change[0], $change[1]);

    $this->postJson("/api/v1/shops/{$shop->id}/products", $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'a value not among its option' => [['variants.0.option_values', ['Purple', '10 L']], 'variants.0.option_values'],
    'two variants the same' => [['variants.2.option_values', ['Red', '10 L']], 'variants.2.option_values'],
    'a was-price below the price' => [['variants.0.compare_at_price', 3000], 'variants.0.compare_at_price'],
    'a price break that costs more' => [['variants.1.price_breaks.1.unit_price', 5800], 'variants.1.price_breaks'],
    'half a piece' => [['variants.1.price_breaks.0.min_quantity', 12.5], 'variants.1.price_breaks'],
    'a SKU twice' => [['variants.2.sku', 'bas-r10'], 'variants.2.sku'],
    'a detail of another category' => [['attributes.0.attribute_id', 'nope'], 'attributes.0.attribute_id'],
]);

it('keeps SKUs unique within the shop, not across shops', function () {
    [$shop] = signedInShopOwner();
    $fx = catalogueFixture();
    $this->postJson("/api/v1/shops/{$shop->id}/products", basin($fx))->assertCreated();
    $this->postJson("/api/v1/shops/{$shop->id}/products", basin($fx, ['name' => 'Other']))
        ->assertUnprocessable()->assertJsonPath('errors', fn ($errors) => isset($errors['variants.0.sku']));

    [$other] = signedInShopOwner();
    $this->postJson("/api/v1/shops/{$other->id}/products", basin($fx))->assertCreated();
});

it('puts a product on sale only with a photo and its required details, then hides it', function () {
    [$shop] = signedInShopOwner(['status' => ShopStatus::Active]);
    $fx = catalogueFixture();
    $id = $this->postJson("/api/v1/shops/{$shop->id}/products", basin($fx, ['attributes' => []]))->json('data.id');

    $this->postJson("/api/v1/products/{$id}/publish")->assertUnprocessable()
        ->assertJsonValidationErrors(['photos', 'attributes'])
        ->assertJsonPath('errors.attributes.0', 'Fill in Capacity: buyers filter by it.');

    $this->putJson("/api/v1/products/{$id}", basin($fx))->assertOk();
    $this->post("/api/v1/products/{$id}/photos", ['photos' => [UploadedFile::fake()->image('a.jpg', 800, 800)]], ['Accept' => 'application/json'])->assertCreated();
    $this->postJson("/api/v1/products/{$id}/publish")->assertOk()->assertJsonPath('data.status', 'Active');

    // On sale, it must stay fit for sale.
    $this->putJson("/api/v1/products/{$id}", basin($fx, ['attributes' => []]))->assertUnprocessable()->assertJsonValidationErrors('attributes');

    $this->postJson("/api/v1/products/{$id}/hide")->assertOk()->assertJsonPath('data.status', 'Hidden');
    $this->deleteJson("/api/v1/products/{$id}")->assertUnprocessable();
    expect(Product::query()->find($id)->published_at)->not->toBeNull();
});

it('deletes a draft that was never on sale, with its photos', function () {
    [$shop] = signedInShopOwner();
    $fx = catalogueFixture();
    $id = $this->postJson("/api/v1/shops/{$shop->id}/products", basin($fx))->json('data.id');
    $this->post("/api/v1/products/{$id}/photos", ['photos' => [UploadedFile::fake()->image('a.jpg', 800, 800)]], ['Accept' => 'application/json'])->assertCreated();
    $photo = ProductPhoto::query()->sole();

    $this->deleteJson("/api/v1/products/{$id}")->assertOk();
    expect(Product::query()->count())->toBe(0);
    Storage::disk('public')->assertMissing($photo->path);
});

it('lets reviewers look, the shop\'s people edit, and nobody else in', function () {
    [$shop] = signedInShopOwner();
    $fx = catalogueFixture();
    $id = $this->postJson("/api/v1/shops/{$shop->id}/products", basin($fx))->json('data.id');

    signedInShopOwner();
    $this->getJson("/api/v1/products/{$id}")->assertForbidden();
    $this->putJson("/api/v1/products/{$id}", basin($fx))->assertForbidden();
    $this->getJson("/api/v1/shops/{$shop->id}/products")->assertForbidden();

    $reviewer = User::factory()->create();
    $reviewer->assignRole('Staff');
    $this->actingAs($reviewer, 'sanctum');
    $this->getJson("/api/v1/products/{$id}")->assertOk();
    $this->getJson("/api/v1/shops/{$shop->id}/products")->assertOk()->assertJsonPath('data.0.min_price', 4000)->assertJsonPath('data.0.max_price', 6000);
    $this->putJson("/api/v1/products/{$id}", basin($fx))->assertForbidden();
    expect(Shop::query()->count())->toBe(2);
});

it('lets Kheengz take a product down, told to the shop, until it is allowed again', function () {
    Notification::fake();
    [$shop, $owner] = signedInShopOwner(['status' => ShopStatus::Active]);
    $fx = catalogueFixture();
    $id = $this->postJson("/api/v1/shops/{$shop->id}/products", basin($fx))->json('data.id');
    $this->post("/api/v1/products/{$id}/photos", ['photos' => [UploadedFile::fake()->image('a.jpg', 800, 800)]], ['Accept' => 'application/json'])->assertCreated();
    $this->postJson("/api/v1/products/{$id}/publish")->assertOk();
    $this->postJson("/api/v1/products/{$id}/block", ['reason' => 'Not allowed'])->assertForbidden();

    actingAsAdmin();
    $this->postJson("/api/v1/products/{$id}/block", [])->assertUnprocessable()->assertJsonValidationErrors('reason');
    $this->postJson("/api/v1/products/{$id}/block", ['reason' => 'The photos are of another brand.'])->assertOk()
        ->assertJsonPath('data.status', 'Hidden')
        ->assertJsonPath('data.blocked_reason', 'The photos are of another brand.');
    Notification::assertSentTo($owner, ProductBlocked::class);

    $this->actingAs($owner, 'sanctum');
    $this->postJson("/api/v1/products/{$id}/publish")->assertUnprocessable()->assertJsonValidationErrors('status');
    $this->putJson("/api/v1/products/{$id}", basin($fx, ['name' => 'Round Basin (own brand)']))->assertOk();

    actingAsAdmin();
    $this->postJson("/api/v1/products/{$id}/unblock")->assertOk();
    $this->actingAs($owner, 'sanctum');
    $this->postJson("/api/v1/products/{$id}/publish")->assertOk()->assertJsonPath('data.status', 'Active');
});
