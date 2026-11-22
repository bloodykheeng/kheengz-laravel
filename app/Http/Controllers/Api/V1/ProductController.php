<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ProductStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Models\StockLevel;
use App\Notifications\ProductBlocked;
use App\Services\ProductCatalogue;
use App\Support\PerPage;
use App\Traits\LoggableTrait;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * A shop's products (D32). The form sends a product whole (details, attributes, options, variants,
 * price breaks, sections) and ProductCatalogue saves it in one go. A product starts as a Draft, goes
 * on sale when published (photos, a variant, the category's required details), and is hidden rather
 * than deleted once it has been on sale. docs/catalogue.md.
 */
#[Group('Products', 'Shop sections, products, variants and photos.', weight: 6)]
class ProductController extends Controller
{
    use LoggableTrait;

    public function __construct(private ProductCatalogue $catalogue) {}

    public function index(Request $request, Shop $shop): AnonymousResourceCollection
    {
        $products = QueryBuilder::for($shop->products()->getQuery())
            ->allowedFilters(
                AllowedFilter::exact('status'),
                AllowedFilter::exact('category_id'),
                AllowedFilter::callback('section_id', fn (Builder $q, $value) => $q->whereHas('sections', fn ($s) => $s->whereKey($value))),
                AllowedFilter::callback('search', fn (Builder $q, $value) => $q->where(fn (Builder $w) => $w
                    ->where('name', 'like', "%{$value}%")
                    ->orWhereHas('variants', fn ($v) => $v->where('sku', 'like', "%{$value}%")->orWhere('barcode', $value)))),
            )
            ->allowedSorts('name', 'status', 'created_at', 'updated_at')
            ->defaultSort('-updated_at')
            ->with(['category', 'photos' => fn ($q) => $q->limit(1)])
            ->withCount(['variants', 'photos', 'trackedVariants'])
            ->addSelect(['stock_available' => StockLevel::query()
                ->join('product_variants', 'product_variants.id', '=', 'stock_levels.product_variant_id')
                ->whereColumn('product_variants.product_id', 'products.id')
                ->where('product_variants.track_stock', true)
                ->selectRaw('coalesce(sum(stock_levels.on_hand - stock_levels.reserved), 0)')])
            ->withMin('variants', 'price')
            ->withMax('variants', 'price')
            ->paginate(PerPage::from($request))
            ->appends($request->query());

        return ProductResource::collection($products);
    }

    public function show(Product $product): ProductResource
    {
        return new ProductResource($this->loadWhole($product));
    }

    public function store(Request $request, Shop $shop): JsonResponse
    {
        $validated = $request->validate($this->rules($shop), $this->messages());
        $product = $this->catalogue->save($shop, null, $validated, $request->user());

        $this->logActivity('product_created', "Product {$product->name} added to {$shop->name}.", ['snapshot' => $this->buildModelSnapshot($product)], $product);

        return response()->json(['message' => "{$product->name} is saved as a draft. Add photos, then put it on sale.", 'data' => new ProductResource($this->loadWhole($product))], 201);
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        $validated = $request->validate($this->rules($product->shop), $this->messages());

        $before = $this->captureOriginal($product);
        $this->catalogue->save($product->shop, $product, $validated, $request->user());
        $this->logActivity('product_updated', "Product {$product->name} updated.", ['changes' => $this->buildChanges($product->refresh(), $before)], $product);

        return response()->json(['message' => 'Saved.', 'data' => new ProductResource($this->loadWhole($product))]);
    }

    /** Put it on sale, or back on sale. It shows to buyers whenever its shop is selling. */
    public function publish(Request $request, Product $product): JsonResponse
    {
        abort_if($product->status === ProductStatus::Active, 422, 'It is already on sale.');
        $this->catalogue->assertFitForSale($product);

        $product->update(['status' => ProductStatus::Active, 'published_at' => $product->published_at ?? now(), 'updated_by' => $request->user()->id]);
        $this->logActivity('product_published', "Product {$product->name} put on sale.", [], $product);

        $selling = $product->shop->isSelling();

        return response()->json([
            'message' => $selling ? "{$product->name} is on sale." : "{$product->name} is ready. Buyers see it once {$product->shop->name} is open.",
            'data' => new ProductResource($this->loadWhole($product)),
        ]);
    }

    /** Off sale, kept with its history. */
    public function hide(Request $request, Product $product): JsonResponse
    {
        abort_if($product->status !== ProductStatus::Active, 422, 'Only a product on sale can be hidden.');

        $product->update(['status' => ProductStatus::Hidden, 'updated_by' => $request->user()->id]);
        $this->logActivity('product_hidden', "Product {$product->name} taken off sale.", [], $product);

        return response()->json(['message' => "{$product->name} is hidden. Put it on sale again whenever you like.", 'data' => new ProductResource($this->loadWhole($product))]);
    }

    /**
     * Kheengz takes a product down (D35): off sale at once, with the reason, and the shop's people are
     * told. They may still fix it, but not put it back on sale until it is allowed again.
     */
    public function block(Request $request, Product $product): JsonResponse
    {
        abort_if($product->isBlocked(), 422, 'It is already taken down.');
        $reason = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']], ['reason.required' => 'Tell the shop why, so they can fix it.'])['reason'];

        $product->update([
            'status' => $product->status === ProductStatus::Draft ? ProductStatus::Draft : ProductStatus::Hidden,
            'blocked_reason' => $reason,
            'blocked_at' => now(),
            'blocked_by' => $request->user()->id,
        ]);
        $this->logActivity('product_blocked', "Product {$product->name} taken down.", ['reason' => $reason], $product);

        $shop = $product->shop;
        $people = $shop->vendor->members()->wherePivot('role', 'Owner')->get()->merge($shop->staff()->get())->unique('id');
        Notification::send($people, new ProductBlocked($product));

        return response()->json(['message' => "{$product->name} is taken down. We told the shop why.", 'data' => new ProductResource($this->loadWhole($product))]);
    }

    /** Allowed again: the shop may put it back on sale itself. */
    public function unblock(Request $request, Product $product): JsonResponse
    {
        abort_unless($product->isBlocked(), 422, 'It is not taken down.');

        $product->update(['blocked_reason' => null, 'blocked_at' => null, 'blocked_by' => null]);
        $this->logActivity('product_unblocked', "Product {$product->name} allowed again.", [], $product);

        return response()->json(['message' => "{$product->name} is allowed again. The shop can put it back on sale.", 'data' => new ProductResource($this->loadWhole($product))]);
    }

    /** A draft that was never on sale. Anything else is hidden instead: orders may point at it. */
    public function destroy(Product $product): JsonResponse
    {
        abort_unless($product->isDeletable(), 422, $product->published_at === null && $product->status === ProductStatus::Draft
            ? 'Stock is on the shelf for this product. Count it to zero first, or keep it as a draft.'
            : 'This product has been on sale, so it is kept for its orders. Hide it instead.');

        $snapshot = $this->buildModelSnapshot($product);
        $this->catalogue->delete($product);
        $this->logActivity('product_deleted', "Draft product {$product->name} deleted.", ['snapshot' => $snapshot], $product);

        return response()->json(['message' => "{$product->name} deleted."]);
    }

    private function loadWhole(Product $product): Product
    {
        return $product->load([
            'category',
            'sections',
            'attributeValues.productAttribute',
            'attributeValues.option',
            'options.values',
            'variants.optionValues.option',
            'variants.unit',
            'variants.priceBreaks',
            'photos',
            'blockedBy',
            'createdBy',
            'updatedBy',
        ]);
    }

    /** @return array<string, list<mixed>> */
    private function rules(Shop $shop): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:150'],
            'category_id' => ['required', 'string', Rule::exists('categories', 'id')],
            'slug' => ['sometimes', 'nullable', 'string', 'max:170', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'collect_only' => ['sometimes', 'boolean'],

            'section_ids' => ['sometimes', 'array'],
            'section_ids.*' => ['string', 'distinct', Rule::exists('shop_sections', 'id')->where('shop_id', $shop->id)],

            'attributes' => ['sometimes', 'array', 'max:40'],
            'attributes.*.attribute_id' => ['required', 'string', 'distinct'],
            'attributes.*.option_id' => ['nullable', 'string'],
            'attributes.*.number' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'attributes.*.text' => ['nullable', 'string', 'max:120'],

            'options' => ['sometimes', 'array', 'max:'.Product::MAX_OPTIONS],
            'options.*.name' => ['required', 'string', 'max:40', 'distinct:ignore_case'],
            'options.*.values' => ['required', 'array', 'min:1', 'max:50'],
            'options.*.values.*' => ['required', 'string', 'max:60', 'distinct:ignore_case'],

            'variants' => ['required', 'array', 'min:1', 'max:'.Product::MAX_VARIANTS],
            'variants.*.id' => ['nullable', 'string'],
            'variants.*.option_values' => ['present', 'array'],
            'variants.*.option_values.*' => ['string', 'max:60'],
            'variants.*.sku' => ['nullable', 'string', 'max:60', 'distinct:ignore_case'],
            'variants.*.barcode' => ['nullable', 'string', 'max:60', 'regex:/^[0-9A-Za-z\-]+$/'],
            'variants.*.unit_id' => ['required', 'string', Rule::exists('units', 'id')->where('is_active', true)],
            'variants.*.price' => ['required', 'integer', 'min:1', 'max:100000000'],
            'variants.*.compare_at_price' => ['nullable', 'integer', 'min:1', 'max:100000000'],
            'variants.*.weight_grams' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'variants.*.is_active' => ['sometimes', 'boolean'],
            'variants.*.track_stock' => ['sometimes', 'boolean'],
            'variants.*.low_stock_at' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'variants.*.price_breaks' => ['sometimes', 'array', 'max:'.ProductVariant::MAX_PRICE_BREAKS],
            'variants.*.price_breaks.*.min_quantity' => ['required', 'numeric', 'gt:1', 'max:1000000'],
            'variants.*.price_breaks.*.unit_price' => ['required', 'integer', 'min:1', 'max:100000000'],
        ];
    }

    /** @return array<string, string> */
    private function messages(): array
    {
        return [
            'category_id.required' => 'Choose the category buyers will find it under.',
            'slug.regex' => 'Use small letters, numbers and dashes only.',
            'variants.required' => 'Set a price.',
            'variants.*.price.required' => 'Set a price.',
            'variants.*.price.min' => 'Set a price.',
            'variants.*.unit_id.required' => 'Choose how it is sold.',
            'variants.*.sku.distinct' => 'Two variants have the same SKU.',
            'options.*.name.distinct' => 'Two options have the same name.',
            'options.*.values.*.distinct' => 'That value is listed twice.',
            'options.*.values.required' => 'Add the values, like 10 L, 20 L.',
            'variants.*.price_breaks.*.min_quantity.gt' => 'A price break starts at 2 or more.',
        ];
    }
}
