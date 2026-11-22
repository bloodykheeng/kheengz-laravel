<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ProductStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProductPhotoResource;
use App\Models\Product;
use App\Models\ProductPhoto;
use App\Support\ImageFromLink;
use App\Support\ProductImage;
use App\Traits\LoggableTrait;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * A product's photos: uploaded, or copied from a pasted link (ImageFromLink), then re-encoded to WebP
 * with metadata stripped (ProductImage). At most Product::MAX_PHOTOS, in the vendor's order; the
 * first is the cover. docs/catalogue.md.
 */
#[Group('Products', 'Shop sections, products, variants and photos.', weight: 6)]
class ProductPhotoController extends Controller
{
    use LoggableTrait;

    /** 15 MB: a phone photo at full size. */
    public const MAX_KB = 15360;

    /** Files (`photos[]`), or one `url`. Added after the photos already there. */
    public function store(Request $request, Product $product, ImageFromLink $links): JsonResponse
    {
        $room = Product::MAX_PHOTOS - $product->photos()->count();
        abort_if($room <= 0, 422, 'A product can have '.Product::MAX_PHOTOS.' photos. Remove one to add another.');

        $validated = $request->validate([
            'photos' => ['required_without:url', 'array', 'max:'.$room],
            'photos.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.self::MAX_KB],
            'url' => ['required_without:photos', 'nullable', 'string', 'max:2048'],
        ], [
            'photos.max' => "You can add {$room} more photo".($room === 1 ? '' : 's').'.',
            'photos.*.mimes' => 'Use JPG, PNG or WebP photos.',
            'photos.*.image' => 'Use JPG, PNG or WebP photos.',
            'photos.*.max' => 'A photo is over 15 MB.',
        ]);

        $folder = "shops/{$product->shop_id}/products/{$product->id}";
        $sources = [];
        if (! empty($validated['url'])) {
            $sources[] = ['bytes' => $links->download($validated['url'], 'url')[0], 'field' => 'url', 'source_url' => trim($validated['url'])];
        }
        foreach ($request->file('photos', []) as $i => $file) {
            $sources[] = ['bytes' => (string) $file->get(), 'field' => "photos.{$i}", 'source_url' => null];
        }

        $position = (int) $product->photos()->max('position') + ($product->photos()->exists() ? 1 : 0);
        $added = collect();
        foreach ($sources as $source) {
            $stored = ProductImage::store($source['bytes'], $folder, $source['field']);
            $added->push($product->photos()->create([
                ...$stored,
                'position' => $position++,
                'source_url' => $source['source_url'],
                'created_by' => $request->user()->id,
            ]));
        }

        $this->logActivity('product_photos_added', $added->count().' photo'.($added->count() === 1 ? '' : 's')." added to {$product->name}.", ['photos' => $added->pluck('path')->all()], $product);

        return response()->json([
            'message' => $added->count() === 1 ? 'Photo added.' : "{$added->count()} photos added.",
            'data' => ProductPhotoResource::collection($product->photos()->get()),
        ], 201);
    }

    /** The order, first (the cover) to last: every photo id of the product. */
    public function reorder(Request $request, Product $product): AnonymousResourceCollection
    {
        $ids = $request->validate(['ids' => ['required', 'array'], 'ids.*' => ['string', 'distinct']])['ids'];
        $own = $product->photos()->pluck('id')->all();
        if (count($ids) !== count($own) || array_diff($ids, $own) !== []) {
            throw ValidationException::withMessages(['ids' => ['The photos changed on another screen. Reload and try again.']]);
        }

        DB::transaction(fn () => collect($ids)->each(fn (string $id, int $position) => ProductPhoto::query()->whereKey($id)->update(['position' => $position])));
        $this->logActivity('product_photos_reordered', "Photos of {$product->name} put in a new order.", [], $product);

        return ProductPhotoResource::collection($product->photos()->get());
    }

    /** Which variant it shows (or none): picking that variant on the product page shows this photo. */
    public function update(Request $request, Product $product, ProductPhoto $photo): JsonResponse
    {
        abort_unless($photo->product_id === $product->id, 404);
        $validated = $request->validate([
            'product_variant_id' => ['present', 'nullable', 'string', Rule::exists('product_variants', 'id')->where('product_id', $product->id)],
        ]);

        $before = $this->captureOriginal($photo);
        $photo->update($validated);
        $this->logActivity('product_photo_updated', "Photo of {$product->name} updated.", ['changes' => $this->buildChanges($photo, $before)], $product);

        return response()->json(['message' => 'Saved.', 'data' => new ProductPhotoResource($photo)]);
    }

    /** A product on sale keeps at least one photo. */
    public function destroy(Product $product, ProductPhoto $photo): JsonResponse
    {
        abort_unless($photo->product_id === $product->id, 404);
        abort_if($product->status === ProductStatus::Active && $product->photos()->count() === 1, 422, 'A product on sale needs a photo. Add another first, or hide the product.');

        $snapshot = $this->buildModelSnapshot($photo);
        $photo->delete();
        $this->logActivity('product_photo_deleted', "Photo removed from {$product->name}.", ['snapshot' => $snapshot], $product);

        return response()->json(['message' => 'Photo removed.']);
    }
}
