<?php

/**
 * Stock (D5, D33): per variant per location, changed only through StockLedger, every change a movement
 * with the figures after it. Nothing goes below zero, holds never exceed what is on hand, and the last
 * item can only be held once.
 */

use App\Enums\StockMovementReason;
use App\Exceptions\InsufficientStock;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Models\User;
use App\Notifications\StockRunningLow;
use App\Services\StockLedger;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    Notification::fake();
});

/**
 * A product of my shop with a piece variant and a kg variant, and two locations.
 *
 * @return array{product: Product, piece: ProductVariant, kg: ProductVariant, main: Location, store: Location, shop: Shop, owner: User}
 */
function stockedShop(): array
{
    [$shop, $owner] = signedInShopOwner();
    $fx = catalogueFixture();
    $main = $shop->locations()->create(['name' => 'Main shop', 'address' => 'Plot 12', 'area' => 'Kikuubo', 'is_default' => true]);
    $store = $shop->locations()->create(['name' => 'Store room', 'address' => 'Plot 14', 'area' => 'Kikuubo']);
    $product = Product::factory()->create(['shop_id' => $shop->id, 'category_id' => $fx['basins']->id, 'name' => 'Round Basin']);
    $piece = ProductVariant::query()->create(['product_id' => $product->id, 'unit_id' => $fx['piece']->id, 'price' => 5000, 'low_stock_at' => 3]);
    $kg = ProductVariant::query()->create(['product_id' => $product->id, 'unit_id' => $fx['kg']->id, 'price' => 3000, 'position' => 1]);

    return compact('product', 'piece', 'kg', 'main', 'store', 'shop', 'owner');
}

function record(Product $product, array $input): TestResponse
{
    return test()->postJson("/api/v1/products/{$product->id}/stock", $input);
}

it('receives, counts, writes off, takes back and moves stock, each a movement', function () {
    ['product' => $product, 'piece' => $piece, 'main' => $main, 'store' => $store] = stockedShop();

    record($product, ['variant_id' => $piece->id, 'location_id' => $main->id, 'reason' => 'Received', 'quantity' => 24, 'note' => 'From Kampala Plastics'])->assertCreated()
        ->assertJsonPath('data.variants.0.available', 24);
    record($product, ['variant_id' => $piece->id, 'location_id' => $main->id, 'reason' => 'Damaged', 'quantity' => 2])->assertCreated();
    record($product, ['variant_id' => $piece->id, 'location_id' => $main->id, 'reason' => 'Returned', 'quantity' => 1])->assertCreated();
    record($product, ['variant_id' => $piece->id, 'location_id' => $main->id, 'reason' => 'Moved', 'quantity' => 10, 'to_location_id' => $store->id])->assertCreated()
        ->assertJsonPath('data.variants.0.levels.0.on_hand', 13)
        ->assertJsonPath('data.variants.0.levels.1.on_hand', 10);
    record($product, ['variant_id' => $piece->id, 'location_id' => $store->id, 'reason' => 'Counted', 'quantity' => 9])->assertCreated()
        ->assertJsonPath('message', 'Counted: 9 on the shelf.')
        ->assertJsonPath('data.variants.0.available', 22);

    $this->getJson("/api/v1/products/{$product->id}/stock/movements?per_page=10")->assertOk()
        ->assertJsonPath('data.*.reason', ['Counted', 'Moved in', 'Moved out', 'Returned', 'Damaged', 'Received'])
        ->assertJsonPath('data.0.on_hand_change', -1)
        ->assertJsonPath('data.0.on_hand_after', 9)
        ->assertJsonPath('data.5.note', 'From Kampala Plastics');
});

it('refuses what is not there, half pieces, and counts below what orders hold', function () {
    ['product' => $product, 'piece' => $piece, 'kg' => $kg, 'main' => $main] = stockedShop();
    record($product, ['variant_id' => $piece->id, 'location_id' => $main->id, 'reason' => 'Received', 'quantity' => 5])->assertCreated();

    record($product, ['variant_id' => $piece->id, 'location_id' => $main->id, 'reason' => 'Damaged', 'quantity' => 6])
        ->assertUnprocessable()->assertJsonPath('errors.quantity.0', 'Only 5 free here: the rest is not there or is held for orders.');
    record($product, ['variant_id' => $piece->id, 'location_id' => $main->id, 'reason' => 'Received', 'quantity' => 1.5])
        ->assertUnprocessable()->assertJsonPath('errors.quantity.0', 'Use whole pieces.');
    record($product, ['variant_id' => $kg->id, 'location_id' => $main->id, 'reason' => 'Received', 'quantity' => 12.75])->assertCreated()
        ->assertJsonPath('data.variants.1.available', 12.75);

    app(StockLedger::class)->hold($piece, $main, '3', 'order', (string) str()->ulid());
    record($product, ['variant_id' => $piece->id, 'location_id' => $main->id, 'reason' => 'Counted', 'quantity' => 2])
        ->assertUnprocessable()->assertJsonPath('errors.quantity.0', 'Orders already hold 3. Count again, or cancel those orders first.');
});

it('holds an order\'s items once, releases them, and sells what was held', function () {
    ['piece' => $piece, 'main' => $main, 'owner' => $owner] = stockedShop();
    $ledger = app(StockLedger::class);
    $ledger->receive($piece, $main, '1', null, $owner);
    $first = (string) str()->ulid();

    $ledger->hold($piece, $main, '1', 'order', $first);
    expect(fn () => $ledger->hold($piece, $main, '1', 'order', (string) str()->ulid()))
        ->toThrow(InsufficientStock::class, 'Only 0 left.');

    $ledger->release($piece, $main, '1', 'order', $first);
    $sold = (string) str()->ulid();
    $ledger->hold($piece, $main, '1', 'order', $sold);
    $movement = $ledger->sell($piece, $main, '1', 'order', $sold);

    expect($movement->reason)->toBe(StockMovementReason::SoldOnline)
        ->and((float) $movement->on_hand_after)->toBe(0.0)
        ->and((float) $movement->reserved_after)->toBe(0.0)
        ->and($movement->source_id)->toBe($sold)
        ->and($ledger->availableMilli($piece))->toBe(0);
});

it('never holds or refuses a variant whose stock is not counted', function () {
    ['product' => $product, 'piece' => $piece, 'main' => $main] = stockedShop();
    $piece->update(['track_stock' => false]);

    expect(app(StockLedger::class)->hold($piece, $main, '100', 'order', (string) str()->ulid()))->toBeNull();
    record($product, ['variant_id' => $piece->id, 'location_id' => $main->id, 'reason' => 'Received', 'quantity' => 5])
        ->assertUnprocessable()->assertJsonValidationErrors('variant_id');
});

it('tells the shop once when a variant falls to its line, and when it runs out', function () {
    ['product' => $product, 'piece' => $piece, 'main' => $main, 'owner' => $owner] = stockedShop();
    record($product, ['variant_id' => $piece->id, 'location_id' => $main->id, 'reason' => 'Received', 'quantity' => 5])->assertCreated();

    record($product, ['variant_id' => $piece->id, 'location_id' => $main->id, 'reason' => 'Damaged', 'quantity' => 2])->assertCreated();
    record($product, ['variant_id' => $piece->id, 'location_id' => $main->id, 'reason' => 'Damaged', 'quantity' => 1])->assertCreated();
    Notification::assertSentToTimes($owner, StockRunningLow::class, 1);

    record($product, ['variant_id' => $piece->id, 'location_id' => $main->id, 'reason' => 'Damaged', 'quantity' => 2])->assertCreated();
    Notification::assertSentToTimes($owner, StockRunningLow::class, 2);
});

it('lists a shop\'s low and empty stock', function () {
    ['product' => $product, 'piece' => $piece, 'kg' => $kg, 'main' => $main, 'shop' => $shop] = stockedShop();
    record($product, ['variant_id' => $piece->id, 'location_id' => $main->id, 'reason' => 'Received', 'quantity' => 2])->assertCreated();

    $this->getJson("/api/v1/shops/{$shop->id}/stock?filter[state]=low")->assertOk()->assertJsonPath('data.*.id', [$piece->id]);
    $this->getJson("/api/v1/shops/{$shop->id}/stock?filter[state]=out")->assertOk()->assertJsonPath('data.*.id', [$kg->id]);
    $this->getJson("/api/v1/shops/{$shop->id}/stock")->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.available', 0);
});

it('keeps stock history: its variant, its location and a draft holding stock stay', function () {
    ['product' => $product, 'piece' => $piece, 'kg' => $kg, 'store' => $store, 'shop' => $shop] = stockedShop();
    record($product, ['variant_id' => $kg->id, 'location_id' => $store->id, 'reason' => 'Received', 'quantity' => 4])->assertCreated();

    $this->deleteJson("/api/v1/shops/{$shop->id}/locations/{$store->id}")->assertUnprocessable();
    $this->deleteJson("/api/v1/products/{$product->id}")->assertUnprocessable()
        ->assertJsonPath('message', 'Stock is on the shelf for this product. Count it to zero first, or keep it as a draft.');

    $category = $product->category_id;
    $this->putJson("/api/v1/products/{$product->id}", [
        'name' => 'Round Basin', 'category_id' => $category,
        'variants' => [['id' => $piece->id, 'option_values' => [], 'unit_id' => $piece->unit_id, 'price' => 5000]],
    ])->assertUnprocessable()->assertJsonValidationErrors('variants');
});

it('lets reviewers look at stock, and keeps other people out', function () {
    ['product' => $product, 'piece' => $piece, 'main' => $main, 'shop' => $shop] = stockedShop();

    signedInShopOwner();
    $this->getJson("/api/v1/products/{$product->id}/stock")->assertForbidden();
    record($product, ['variant_id' => $piece->id, 'location_id' => $main->id, 'reason' => 'Received', 'quantity' => 5])->assertForbidden();

    $reviewer = User::factory()->create();
    $reviewer->assignRole('Staff');
    $this->actingAs($reviewer, 'sanctum');
    $this->getJson("/api/v1/products/{$product->id}/stock")->assertOk();
    $this->getJson("/api/v1/shops/{$shop->id}/stock")->assertOk();
    record($product, ['variant_id' => $piece->id, 'location_id' => $main->id, 'reason' => 'Received', 'quantity' => 5])->assertForbidden();
});
