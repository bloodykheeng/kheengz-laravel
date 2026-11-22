<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ShopType;
use App\Http\Controllers\Controller;
use App\Http\Resources\UnitResource;
use App\Models\ProductVariant;
use App\Models\Unit;
use App\Support\PerPage;
use App\Traits\LoggableTrait;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Units of sale (D17): piece, dozen, kg, metre. The Admin keeps the list (`manage catalogue`); a
 * product form asks with its shop's type and gets that type's units first (ShopType::suggestedUnits).
 * A short list, so not paged. docs/catalogue.md.
 */
#[Group('Catalogue', 'Categories, attributes, units and category requests.', weight: 5)]
class UnitController extends Controller
{
    use LoggableTrait;

    /**
     * Pickers get the whole short list (`active`, `shop_type`); the Units screen pages it like every list
     * (`page`, `filter[search]`, `sort`).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        if ($request->has('page')) {
            return UnitResource::collection(QueryBuilder::for(Unit::query())
                ->allowedFilters(AllowedFilter::callback('search', fn ($q, $value) => $q->where(fn ($w) => $w->where('name', 'like', "%{$value}%")->orWhere('plural', 'like', "%{$value}%"))))
                ->allowedSorts('name', 'position', 'created_at')
                ->defaultSort('position')
                ->paginate(PerPage::from($request))
                ->appends($request->query()));
        }

        $request->validate(['shop_type' => ['sometimes', Rule::enum(ShopType::class)]]);
        $units = Unit::query()->when($request->boolean('active'), fn ($q) => $q->where('is_active', true))
            ->orderBy('position')->orderBy('name')->get();

        if ($request->filled('shop_type')) {
            $suggested = ShopType::from($request->string('shop_type')->value())->suggestedUnits();
            $units->each(fn (Unit $u) => $u->setAttribute('suggested', in_array($u->name, $suggested, true)));
            $units = $units->sortBy(fn (Unit $u) => [! $u->getAttribute('suggested'), array_search($u->name, $suggested, true), $u->position])->values();
        }

        return UnitResource::collection($units);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validated($request, null);
        $by = $request->user()->id;
        $unit = Unit::query()->create([
            ...$validated,
            'position' => $validated['position'] ?? (int) Unit::query()->max('position') + 1,
            'created_by' => $by,
            'updated_by' => $by,
        ]);
        $this->logActivity('unit_created', "Unit {$unit->name} created.", ['snapshot' => $this->buildModelSnapshot($unit)], $unit);

        return response()->json(['message' => "{$unit->name} added.", 'data' => new UnitResource($unit)], 201);
    }

    public function update(Request $request, Unit $unit): JsonResponse
    {
        $validated = $this->validated($request, $unit);
        $inUse = ProductVariant::query()->where('unit_id', $unit->id)->exists();
        abort_if($inUse && isset($validated['allows_decimals']) && $validated['allows_decimals'] !== $unit->allows_decimals, 422, 'Products are sold by this unit, so whether it takes decimals stays.');

        $before = $this->captureOriginal($unit);
        $unit->update([...$validated, 'updated_by' => $request->user()->id]);
        $this->logActivity('unit_updated', "Unit {$unit->name} updated.", ['changes' => $this->buildChanges($unit, $before)], $unit);

        return response()->json(['message' => 'Saved.', 'data' => new UnitResource($unit)]);
    }

    /** Ticked units, all or none: if products are sold by any of them, nothing is deleted. */
    public function bulkDestroy(Request $request): JsonResponse
    {
        $ids = $request->validate(['ids' => ['required', 'array', 'max:100'], 'ids.*' => ['string', 'distinct', Rule::exists('units', 'id')]])['ids'];
        $units = Unit::query()->whereIn('id', $ids)->get();
        $used = $units->first(fn (Unit $u) => ProductVariant::query()->where('unit_id', $u->id)->exists());
        abort_if($used !== null, 422, "Products are sold by the {$used?->name}, so nothing was deleted. Untick it, or turn it off instead.");

        DB::transaction(function () use ($units) {
            foreach ($units as $unit) {
                $snapshot = $this->buildModelSnapshot($unit);
                $unit->delete();
                $this->logActivity('unit_deleted', "Unit {$unit->name} deleted.", ['snapshot' => $snapshot], $unit);
            }
        });

        return response()->json(['message' => $units->count() === 1 ? "{$units->first()?->name} deleted." : "{$units->count()} units deleted."]);
    }

    public function destroy(Unit $unit): JsonResponse
    {
        abort_if(ProductVariant::query()->where('unit_id', $unit->id)->exists(), 422, 'Products are sold by this unit. Turn it off instead.');

        $snapshot = $this->buildModelSnapshot($unit);
        $unit->delete();
        $this->logActivity('unit_deleted', "Unit {$unit->name} deleted.", ['snapshot' => $snapshot], $unit);

        return response()->json(['message' => "{$unit->name} deleted."]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Unit $unit): array
    {
        $required = $unit === null ? 'required' : 'sometimes';

        return $request->validate([
            'name' => [$required, 'string', 'min:1', 'max:30', Rule::unique('units', 'name')->ignore($unit?->id)],
            'plural' => [$required, 'string', 'min:1', 'max:30'],
            'allows_decimals' => ['sometimes', 'boolean'],
            'position' => ['sometimes', 'integer', 'min:0', 'max:10000'],
            'is_active' => ['sometimes', 'boolean'],
        ], ['name.unique' => 'That unit is already on the list.']);
    }
}
