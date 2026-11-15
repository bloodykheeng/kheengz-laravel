<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\LocationResource;
use App\Models\Location;
use App\Models\Shop;
use App\Rules\PhoneNumber;
use App\Support\Phone;
use App\Traits\LoggableTrait;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * A shop's locations (D5): the shop, its store rooms. One is always the default, which orders ship
 * from unless the shop picks another. docs/vendors-and-shops.md.
 */
#[Group('Shops', 'Shops, their review and their locations.', weight: 4)]
class LocationController extends Controller
{
    use LoggableTrait;

    public function index(Request $request, Shop $shop): AnonymousResourceCollection
    {
        $this->authorize('view', $shop);

        return LocationResource::collection($shop->locations()->orderByDesc('is_default')->orderBy('name')->get());
    }

    public function store(Request $request, Shop $shop): JsonResponse
    {
        $this->authorize('update', $shop);
        $validated = $this->validated($request);
        $by = $request->user()->id;

        $location = DB::transaction(function () use ($shop, $validated, $by) {
            if ($validated['is_default'] ?? false) {
                $shop->locations()->update(['is_default' => false]);
            }

            return $shop->locations()->create([...$validated, 'is_default' => $validated['is_default'] ?? false, 'created_by' => $by, 'updated_by' => $by]);
        });

        $this->logActivity('location_added', "Location {$location->name} added to {$shop->name}.", ['snapshot' => $this->buildModelSnapshot($location)], $shop);

        return response()->json(['message' => "{$location->name} added.", 'data' => new LocationResource($location)], 201);
    }

    public function update(Request $request, Shop $shop, Location $location): JsonResponse
    {
        $this->authorize('update', $shop);
        abort_unless($location->shop_id === $shop->id, 404);
        $validated = $this->validated($request);

        abort_if($location->is_default && array_key_exists('is_default', $validated) && ! $validated['is_default'], 422, 'Make another location the default instead: one always is.');

        $before = $this->captureOriginal($location);
        DB::transaction(function () use ($shop, $location, $validated, $request) {
            if ($validated['is_default'] ?? false) {
                $shop->locations()->whereKeyNot($location->id)->update(['is_default' => false]);
            }
            $location->update([...$validated, 'updated_by' => $request->user()->id]);
        });

        $this->logActivity('location_updated', "Location {$location->name} of {$shop->name} updated.", ['changes' => $this->buildChanges($location, $before)], $shop);

        return response()->json(['message' => 'Saved.', 'data' => new LocationResource($location->fresh())]);
    }

    /** Not the default, and not the last one: a shop always sends from somewhere. */
    public function destroy(Request $request, Shop $shop, Location $location): JsonResponse
    {
        $this->authorize('update', $shop);
        abort_unless($location->shop_id === $shop->id, 404);
        abort_if($location->is_default, 422, 'This is the default location. Make another one the default first.');

        $snapshot = $this->buildModelSnapshot($location);
        $location->delete();
        $this->logActivity('location_deleted', "Location {$location->name} removed from {$shop->name}.", ['snapshot' => $snapshot], $shop);

        return response()->json(['message' => "{$location->name} removed."]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        if ($request->has('phone')) {
            $request->merge(['phone' => Phone::international($request->input('phone')) ?? $request->input('phone')]);
        }

        return $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'address' => ['required', 'string', 'max:255'],
            'area' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', new PhoneNumber],
            'opening_hours' => ['nullable', 'string', 'max:255'],
            'is_default' => ['sometimes', 'boolean'],
        ]);
    }
}
