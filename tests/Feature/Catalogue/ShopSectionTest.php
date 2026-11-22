<?php

/**
 * A shop's own sections (D32): named and ordered by the vendor, run only by the shop's people.
 */

use App\Enums\ShopStatus;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

it('adds, renames, orders and removes sections, keeping the products', function () {
    Storage::fake('public');
    [$shop] = signedInShopOwner();

    $school = $this->postJson("/api/v1/shops/{$shop->id}/sections", ['name' => 'Back to school'])->assertCreated()
        ->assertJsonPath('data.slug', 'back-to-school')->json('data');
    $wedding = $this->post("/api/v1/shops/{$shop->id}/sections", ['name' => 'Wedding sets', 'photo' => UploadedFile::fake()->image('w.jpg', 900, 600)], ['Accept' => 'application/json'])
        ->assertCreated()->json('data');
    expect($wedding['photo_url'])->toEndWith('-thumb.webp');

    $this->postJson("/api/v1/shops/{$shop->id}/sections", ['name' => 'back to school'])->assertUnprocessable()->assertJsonValidationErrors('name');
    $this->putJson("/api/v1/shops/{$shop->id}/sections/{$school['id']}", ['name' => 'School term'])->assertOk()->assertJsonPath('data.slug', 'school-term');

    $this->putJson("/api/v1/shops/{$shop->id}/sections/order", ['ids' => [$wedding['id'], $school['id']]])->assertOk()
        ->assertJsonPath('data.*.name', ['Wedding sets', 'School term']);
    $this->putJson("/api/v1/shops/{$shop->id}/sections/order", ['ids' => [$wedding['id']]])->assertUnprocessable();

    $product = Product::factory()->create(['shop_id' => $shop->id, 'category_id' => Category::factory()->create()->id]);
    $product->sections()->attach($school['id']);
    $this->deleteJson("/api/v1/shops/{$shop->id}/sections/{$school['id']}")->assertOk();
    expect($product->fresh())->not->toBeNull()->and($product->sections()->count())->toBe(0);
});

it('keeps other people out, and a closed shop shut', function () {
    [$theirs] = signedInShopOwner();
    $section = $theirs->sections()->create(['name' => 'Theirs', 'slug' => 'theirs']);

    [$mine] = signedInShopOwner(['status' => ShopStatus::Closed]);
    $this->getJson("/api/v1/shops/{$theirs->id}/sections")->assertForbidden();
    $this->putJson("/api/v1/shops/{$theirs->id}/sections/{$section->id}", ['name' => 'Mine now'])->assertForbidden();
    $this->postJson("/api/v1/shops/{$mine->id}/sections", ['name' => 'After closing'])->assertForbidden();

    // A section is reached only through its own shop.
    [$open] = signedInShopOwner();
    $this->putJson("/api/v1/shops/{$open->id}/sections/{$section->id}", ['name' => 'Crossed'])->assertNotFound();
});
