<?php

/**
 * Product photos: re-encoded to WebP at two sizes with metadata gone, ordered by the vendor, copied
 * from a pasted link only from public sites.
 */

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\ProductPhoto;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Support\ImageFromLink;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    Storage::fake('public');
});

/** @return array{0: Product, 1: Shop} */
function productOfMyShop(): array
{
    [$shop] = signedInShopOwner();
    $fx = catalogueFixture();
    $product = Product::factory()->create(['shop_id' => $shop->id, 'category_id' => $fx['basins']->id]);
    ProductVariant::query()->create(['product_id' => $product->id, 'unit_id' => $fx['piece']->id, 'price' => 5000]);

    return [$product, $shop];
}

function uploadPhotos(Product $product, array $files): TestResponse
{
    return test()->post("/api/v1/products/{$product->id}/photos", ['photos' => $files], ['Accept' => 'application/json']);
}

it('keeps a phone photo as WebP at two sizes, never bigger than 1600 px', function () {
    [$product] = productOfMyShop();

    uploadPhotos($product, [UploadedFile::fake()->image('basin.jpg', 4000, 3000)])->assertCreated()
        ->assertJsonPath('data.0.width', 1600)
        ->assertJsonPath('data.0.height', 1200);

    $photo = ProductPhoto::query()->sole();
    Storage::disk('public')->assertExists([$photo->path, $photo->thumb_path]);
    expect($photo->path)->toEndWith('.webp')
        ->and(getimagesizefromstring(Storage::disk('public')->get($photo->thumb_path))[0])->toBe(480)
        ->and(getimagesizefromstring(Storage::disk('public')->get($photo->path))['mime'])->toBe('image/webp');
});

it('refuses photos too small to show, and more than ten', function () {
    [$product] = productOfMyShop();

    uploadPhotos($product, [UploadedFile::fake()->image('tiny.jpg', 300, 300)])->assertUnprocessable()->assertJsonValidationErrors('photos.0');
    uploadPhotos($product, array_fill(0, 11, UploadedFile::fake()->image('p.jpg', 500, 500)))->assertUnprocessable()->assertJsonValidationErrors('photos');
});

it('orders the photos, ties one to a variant, and keeps the last photo of a product on sale', function () {
    [$product] = productOfMyShop();
    $ids = collect(uploadPhotos($product, [UploadedFile::fake()->image('a.jpg', 600, 600), UploadedFile::fake()->image('b.jpg', 600, 600)])->json('data'))->pluck('id');

    $this->putJson("/api/v1/products/{$product->id}/photos/order", ['ids' => $ids->reverse()->values()->all()])->assertOk()
        ->assertJsonPath('data.0.id', $ids[1]);
    $variant = $product->variants()->first();
    $this->putJson("/api/v1/products/{$product->id}/photos/{$ids[0]}", ['product_variant_id' => $variant->id])->assertOk()
        ->assertJsonPath('data.product_variant_id', $variant->id);

    $product->update(['status' => ProductStatus::Active, 'published_at' => now()]);
    $this->deleteJson("/api/v1/products/{$product->id}/photos/{$ids[0]}")->assertOk();
    $this->deleteJson("/api/v1/products/{$product->id}/photos/{$ids[1]}")->assertUnprocessable();
});

it('copies a photo from a public link, and refuses private addresses', function () {
    [$product] = productOfMyShop();
    $jpeg = UploadedFile::fake()->image('remote.jpg', 900, 700)->get();
    Http::fake(['shop.example/*' => Http::response($jpeg, 200, ['Content-Type' => 'image/jpeg'])]);

    app()->instance(ImageFromLink::class, new ImageFromLink(fn (string $host) => $host === 'shop.example' ? ['93.184.216.34'] : ['10.0.0.5']));
    $this->postJson("/api/v1/products/{$product->id}/photos", ['url' => 'https://shop.example/basin.jpg'])->assertCreated()
        ->assertJsonPath('data.0.width', 900);
    expect(ProductPhoto::query()->sole()->source_url)->toBe('https://shop.example/basin.jpg');

    $this->postJson("/api/v1/products/{$product->id}/photos", ['url' => 'https://intranet.local/x.jpg'])->assertUnprocessable()
        ->assertJsonPath('errors.url.0', 'That link does not point to a public website.');
    $this->postJson("/api/v1/products/{$product->id}/photos", ['url' => 'http://169.254.169.254/latest/meta-data'])->assertUnprocessable();
});
