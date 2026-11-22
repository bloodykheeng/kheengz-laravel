<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ShopSectionResource;
use App\Models\Shop;
use App\Models\ShopSection;
use App\Support\ProductImage;
use App\Traits\LoggableTrait;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A shop's own sections (D32): "Back to school", "Under 10k". Named and ordered by the vendor, at
 * most ShopSection::MAX_PER_SHOP. A short list, so not paged. docs/catalogue.md.
 */
#[Group('Products', 'Shop sections, products, variants and photos.', weight: 6)]
class ShopSectionController extends Controller
{
    use LoggableTrait;

    public function index(Shop $shop): AnonymousResourceCollection
    {
        return ShopSectionResource::collection($shop->sections()->withCount('products')->get());
    }

    public function store(Request $request, Shop $shop): JsonResponse
    {
        abort_if($shop->sections()->count() >= ShopSection::MAX_PER_SHOP, 422, 'A shop can have '.ShopSection::MAX_PER_SHOP.' sections. Join two, or remove one you no longer use.');
        $validated = $this->validated($request, $shop, null);
        $by = $request->user()->id;

        $section = $shop->sections()->create([
            'name' => $validated['name'],
            'slug' => $this->uniqueSlug($shop, $validated['name'], null),
            'is_active' => $validated['is_active'] ?? true,
            'position' => (int) $shop->sections()->max('position') + 1,
            'created_by' => $by,
            'updated_by' => $by,
        ]);
        $this->storePhoto($request, $section);

        $this->logActivity('shop_section_created', "Section {$section->name} added to {$shop->name}.", ['snapshot' => $this->buildModelSnapshot($section)], $section);

        return response()->json(['message' => "{$section->name} added.", 'data' => new ShopSectionResource($section)], 201);
    }

    /** Sent as multipart when a photo comes with it (POST with `_method=PUT`). */
    public function update(Request $request, Shop $shop, ShopSection $section): JsonResponse
    {
        $validated = $this->validated($request, $shop, $section);

        $before = $this->captureOriginal($section);
        $section->fill([...collect($validated)->except(['photo', 'remove_photo'])->all(), 'updated_by' => $request->user()->id]);
        if ($section->isDirty('name')) {
            $section->slug = $this->uniqueSlug($shop, $section->name, $section->id);
        }
        $section->save();
        if ($request->boolean('remove_photo') && $section->photo_path) {
            Storage::disk('public')->delete($section->photo_path);
            $section->update(['photo_path' => null]);
        }
        $this->storePhoto($request, $section);

        $this->logActivity('shop_section_updated', "Section {$section->name} of {$shop->name} updated.", ['changes' => $this->buildChanges($section, $before)], $section);

        return response()->json(['message' => 'Saved.', 'data' => new ShopSectionResource($section->loadCount('products'))]);
    }

    /** Its products stay in the shop; they only leave this section. */
    public function destroy(Shop $shop, ShopSection $section): JsonResponse
    {
        $snapshot = $this->buildModelSnapshot($section);
        DB::transaction(function () use ($section) {
            $section->products()->detach();
            $section->delete();
        });
        $this->logActivity('shop_section_deleted', "Section {$section->name} removed from {$shop->name}.", ['snapshot' => $snapshot], $section);

        return response()->json(['message' => "{$section->name} removed. Its products are still in the shop."]);
    }

    /** The menu order: every section id of the shop, first to last. */
    public function reorder(Request $request, Shop $shop): JsonResponse
    {
        $ids = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['string', 'distinct'],
        ])['ids'];

        $own = $shop->sections()->pluck('id')->all();
        if (count($ids) !== count($own) || array_diff($ids, $own) !== []) {
            throw ValidationException::withMessages(['ids' => ['The sections changed on another screen. Reload and try again.']]);
        }

        DB::transaction(fn () => collect($ids)->each(fn (string $id, int $position) => ShopSection::query()->whereKey($id)->update(['position' => $position])));
        $this->logActivity('shop_sections_reordered', "Sections of {$shop->name} put in a new order.", ['order' => $shop->sections()->pluck('name')->all()], $shop);

        return response()->json(['message' => 'Order saved.', 'data' => ShopSectionResource::collection($shop->sections()->withCount('products')->get())]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, Shop $shop, ?ShopSection $section): array
    {
        $required = $section === null ? 'required' : 'sometimes';

        $validated = $request->validate([
            'name' => [$required, 'string', 'min:2', 'max:60'],
            'is_active' => ['sometimes', 'boolean'],
            'photo' => ['sometimes', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:15360'],
            'remove_photo' => ['sometimes', 'boolean'],
        ], ['photo.max' => 'The photo is over 15 MB.']);

        // "School" and "school" are one section to a buyer, whatever the database's collation says.
        if (isset($validated['name'])) {
            $validated['name'] = trim($validated['name']);
            $taken = $shop->sections()->whereRaw('lower(name) = ?', [mb_strtolower($validated['name'])])
                ->when($section, fn ($q) => $q->whereKeyNot($section->id))->exists();
            if ($taken) {
                throw ValidationException::withMessages(['name' => ['Your shop already has a section with that name.']]);
            }
        }

        return $validated;
    }

    private function storePhoto(Request $request, ShopSection $section): void
    {
        if (! $request->hasFile('photo')) {
            return;
        }
        $stored = ProductImage::store((string) $request->file('photo')?->get(), "shops/{$section->shop_id}/sections", 'photo');
        Storage::disk('public')->delete($stored['path']);
        $old = $section->photo_path;
        $section->update(['photo_path' => $stored['thumb_path']]);
        if ($old) {
            Storage::disk('public')->delete($old);
        }
    }

    private function uniqueSlug(Shop $shop, string $name, ?string $ignore): string
    {
        $base = Str::slug($name) ?: 'section';
        $slug = $base;
        for ($n = 2; $shop->sections()->where('slug', $slug)->when($ignore, fn ($q) => $q->whereKeyNot($ignore))->exists(); $n++) {
            $slug = "{$base}-{$n}";
        }

        return $slug;
    }
}
