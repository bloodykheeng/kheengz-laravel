<?php

/**
 * A shop asks for a category the tree lacks (D32); the Admin adds it or says why not, and whoever asked
 * is told either way.
 */

use App\Models\Category;
use App\Models\Shop;
use App\Models\User;
use App\Notifications\CategoryRequestDecided;
use App\Notifications\CategoryRequested;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
});

it('takes a request from a shop and tells the Admins', function () {
    [$shop] = signedInShopOwner();
    $admin = User::factory()->create(['status' => 'Active']);
    $admin->assignRole('Admin');
    $kitchen = Category::factory()->create(['name' => 'Kitchen']);

    $this->postJson("/api/v1/shops/{$shop->id}/category-requests", ['name' => 'Water filters', 'parent_id' => $kitchen->id, 'note' => 'Ceramic candle filters'])
        ->assertCreated()->assertJsonPath('data.status', 'Pending');

    Notification::assertSentTo($admin, CategoryRequested::class);

    $this->postJson("/api/v1/shops/{$shop->id}/category-requests", ['name' => 'water filters'])
        ->assertUnprocessable()->assertJsonPath('errors.name.0', 'You have already asked for this one. We will tell you when it is decided.');
    $this->postJson("/api/v1/shops/{$shop->id}/category-requests", ['name' => 'kitchen'])
        ->assertUnprocessable()->assertJsonPath('errors.name.0', 'There is already Kitchen. Choose it on the product.');
});

it('adds the category asked for, once, and tells whoever asked', function () {
    [$shop, $owner] = signedInShopOwner();
    $kitchen = Category::factory()->create(['name' => 'Kitchen']);
    $id = $this->postJson("/api/v1/shops/{$shop->id}/category-requests", ['name' => 'Water filters', 'parent_id' => $kitchen->id])->json('data.id');

    actingAsAdmin();
    $this->postJson("/api/v1/category-requests/{$id}/add", ['name' => 'Water filters', 'parent_id' => $kitchen->id, 'answer' => 'Added under Kitchen.'])
        ->assertOk()
        ->assertJsonPath('data.status', 'Added')
        ->assertJsonPath('data.category.path', 'Kitchen › Water filters');

    Notification::assertSentTo($owner, CategoryRequestDecided::class);
    $this->postJson("/api/v1/category-requests/{$id}/decline", ['answer' => 'Too late now.'])
        ->assertUnprocessable()->assertJsonValidationErrors('status');
});

it('answers with a category that already fits, or declines with a reason', function () {
    [$shop] = signedInShopOwner();
    $one = $this->postJson("/api/v1/shops/{$shop->id}/category-requests", ['name' => 'Kids cups'])->json('data.id');
    $two = $this->postJson("/api/v1/shops/{$shop->id}/category-requests", ['name' => 'Second-hand phones'])->json('data.id');
    $cups = Category::factory()->create(['name' => 'Cups & mugs']);

    actingAsAdmin();
    $this->postJson("/api/v1/category-requests/{$one}/add", ['category_id' => $cups->id])->assertOk()->assertJsonPath('data.category.id', $cups->id);
    $this->postJson("/api/v1/category-requests/{$two}/decline", [])->assertUnprocessable()->assertJsonValidationErrors('answer');
    $this->postJson("/api/v1/category-requests/{$two}/decline", ['answer' => 'We list phones as Phones, new or used.'])->assertOk()->assertJsonPath('data.status', 'Declined');
    expect(Category::query()->count())->toBe(1);
});

it('shows a vendor only their own shops\' requests', function () {
    [$other] = signedInShopOwner();
    $this->postJson("/api/v1/shops/{$other->id}/category-requests", ['name' => 'Theirs'])->assertCreated();

    [$shop] = signedInShopOwner();
    $this->postJson("/api/v1/shops/{$shop->id}/category-requests", ['name' => 'Mine'])->assertCreated();

    $this->getJson('/api/v1/category-requests')->assertOk()->assertJsonPath('data.*.name', ['Mine']);
    $this->postJson("/api/v1/shops/{$other->id}/category-requests", ['name' => 'Sneaky'])->assertForbidden();
    expect(Shop::query()->count())->toBe(2);
});
