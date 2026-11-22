<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\StockMovementReason;
use App\Exceptions\InsufficientStock;
use App\Http\Controllers\Controller;
use App\Http\Resources\StockMovementResource;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\StockLedger;
use App\Support\PerPage;
use App\Support\Quantity;
use App\Traits\LoggableTrait;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Stock (D5, D33): a product's figures per variant per location, its history, and what a shop's people
 * record by hand (received, counted, damaged, returned, moved). Orders hold and sell stock themselves
 * (step 5) through the same StockLedger. docs/stock.md.
 */
#[Group('Stock', 'Stock per variant per location, and its history.', weight: 7)]
class StockController extends Controller
{
    use LoggableTrait;

    public function __construct(private StockLedger $ledger) {}

    /** Every variant against every location of its shop: on hand, held, free, and the totals. */
    public function show(Product $product): JsonResponse
    {
        return response()->json(['data' => $this->card($product)]);
    }

    /**
     * Record a change by hand. `Moved` takes `to_location_id` and is written as two movements.
     * `Counted` sets what is on the shelf; the others add or take away `quantity`.
     */
    public function store(Request $request, Product $product): JsonResponse
    {
        $manual = [...array_map(fn (StockMovementReason $r) => $r->value, StockMovementReason::manual()), 'Moved'];
        $validated = $request->validate([
            'variant_id' => ['required', 'string', Rule::exists('product_variants', 'id')->where('product_id', $product->id)],
            'location_id' => ['required', 'string', Rule::exists('locations', 'id')->where('shop_id', $product->shop_id)],
            'reason' => ['required', Rule::in($manual)],
            'quantity' => ['required', 'numeric', $request->input('reason') === 'Counted' ? 'min:0' : 'gt:0', 'max:1000000'],
            'to_location_id' => ['required_if:reason,Moved', 'nullable', 'string', 'different:location_id', Rule::exists('locations', 'id')->where('shop_id', $product->shop_id)],
            'note' => ['nullable', 'string', 'max:255'],
        ], [
            'quantity.gt' => 'Enter how many.',
            'to_location_id.required_if' => 'Choose where it is moving to.',
            'to_location_id.different' => 'Choose another location to move it to.',
        ]);

        $variant = ProductVariant::query()->with(['unit', 'optionValues.option'])->findOrFail($validated['variant_id']);
        if (! $variant->track_stock) {
            throw ValidationException::withMessages(['variant_id' => ['Stock is not counted for this variant. Switch on "Count stock" on the product first.']]);
        }
        if (! $variant->unit->allows_decimals && ! Quantity::isWhole($validated['quantity'])) {
            throw ValidationException::withMessages(['quantity' => ["Use whole {$variant->unit->plural}."]]);
        }

        $location = Location::query()->findOrFail($validated['location_id']);
        $quantity = Quantity::tidy($validated['quantity']);
        $note = $validated['note'] ?? null;
        $by = $request->user();

        try {
            $movements = $validated['reason'] === 'Moved'
                ? $this->ledger->move($variant, $location, Location::query()->findOrFail($validated['to_location_id']), $quantity, $note, $by)
                : [$this->recordByHand(StockMovementReason::from($validated['reason']), $variant, $location, $quantity, $note, $by)];
        } catch (InsufficientStock $e) {
            throw ValidationException::withMessages(['quantity' => [$e->getMessage()]]);
        }

        $name = trim($product->name.' '.$variant->label());
        $this->logActivity('stock_recorded', "{$validated['reason']}: {$quantity} {$variant->unit->plural} of {$name} at {$location->name}.", [
            'movements' => array_map(fn (StockMovement $m) => $m->only(['id', 'reason', 'on_hand_change', 'on_hand_after', 'location_id']), $movements),
            'note' => $note,
        ], $product);

        return response()->json(['message' => match ($validated['reason']) {
            'Counted' => "Counted: {$quantity} on the shelf.",
            'Moved' => "Moved {$quantity}.",
            default => 'Saved.',
        }, 'data' => $this->card($product)], 201);
    }

    /** One of the changes a person records (StockMovementReason::manual); orders' changes are not theirs. */
    private function recordByHand(StockMovementReason $reason, ProductVariant $variant, Location $location, string $quantity, ?string $note, User $by): StockMovement
    {
        return match ($reason) {
            StockMovementReason::Received => $this->ledger->receive($variant, $location, $quantity, $note, $by),
            StockMovementReason::Returned => $this->ledger->returned($variant, $location, $quantity, $note, $by),
            StockMovementReason::Damaged => $this->ledger->damaged($variant, $location, $quantity, $note, $by),
            StockMovementReason::Counted => $this->ledger->count($variant, $location, $quantity, $note, $by),
            StockMovementReason::MovedOut, StockMovementReason::MovedIn, StockMovementReason::Held, StockMovementReason::Released, StockMovementReason::SoldOnline => throw new InvalidArgumentException("{$reason->value} is not recorded by hand."),
        };
    }

    /** A product's stock history, newest first. */
    public function movements(Request $request, Product $product): AnonymousResourceCollection
    {
        $movements = QueryBuilder::for(StockMovement::query()->whereIn('product_variant_id', $product->variants()->select('id')))
            ->allowedFilters(AllowedFilter::exact('variant_id', 'product_variant_id'), AllowedFilter::exact('location_id'), AllowedFilter::exact('reason'))
            ->allowedSorts('created_at')
            ->defaultSort('-created_at')
            ->with(['location', 'createdBy', 'variant.optionValues.option', 'variant.unit'])
            ->paginate(PerPage::from($request))
            ->appends($request->query());

        return StockMovementResource::collection($movements);
    }

    /**
     * A shop's counted variants with what is free of each, for the shop's stock page. `filter[state]`:
     * `low` (at or under its line), `out` (none free).
     */
    public function shop(Request $request, Shop $shop): JsonResponse
    {
        $free = '(select coalesce(sum(on_hand - reserved), 0) from stock_levels where stock_levels.product_variant_id = product_variants.id)';
        $onHand = '(select coalesce(sum(on_hand), 0) from stock_levels where stock_levels.product_variant_id = product_variants.id)';

        $rows = QueryBuilder::for(ProductVariant::query()
            ->whereIn('product_id', $shop->products()->select('id'))
            ->where('track_stock', true)
            ->select('product_variants.*')
            ->selectRaw("{$free} as available, {$onHand} as on_hand"))
            ->allowedFilters(
                AllowedFilter::callback('state', fn (Builder $q, $value) => match ($value) {
                    'out' => $q->whereRaw("{$free} <= 0"),
                    'low' => $q->whereRaw("{$free} > 0")->whereNotNull('low_stock_at')->whereRaw("{$free} <= low_stock_at"),
                    default => $q,
                }),
                AllowedFilter::callback('search', fn (Builder $q, $value) => $q->where(fn (Builder $w) => $w
                    ->where('sku', 'like', "%{$value}%")
                    ->orWhere('barcode', $value)
                    ->orWhereHas('product', fn ($p) => $p->where('name', 'like', "%{$value}%")))),
            )
            ->allowedSorts('available')
            ->defaultSort('available')
            ->with(['product', 'unit', 'optionValues.option'])
            ->paginate(PerPage::from($request))
            ->appends($request->query());

        return response()->json([
            'data' => $rows->getCollection()->map(fn (ProductVariant $v) => [
                'id' => $v->id,
                'product_id' => $v->product_id,
                'product_name' => $v->product->name,
                'label' => $v->label(),
                'sku' => $v->sku,
                'unit' => ['name' => $v->unit->name, 'plural' => $v->unit->plural, 'allows_decimals' => $v->unit->allows_decimals],
                'available' => (float) $v->getAttribute('available'),
                'on_hand' => (float) $v->getAttribute('on_hand'),
                'low_stock_at' => $v->low_stock_at === null ? null : (float) $v->low_stock_at,
                'is_active' => $v->is_active,
            ])->values(),
            'meta' => ['total' => $rows->total(), 'per_page' => $rows->perPage(), 'current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage()],
        ]);
    }

    /** @return array<string, mixed> */
    private function card(Product $product): array
    {
        $locations = $product->shop->locations()->orderByDesc('is_default')->orderBy('name')->get();
        $variants = $product->variants()->with(['unit', 'optionValues.option'])->get();
        $levels = StockLevel::query()->whereIn('product_variant_id', $variants->pluck('id'))->get()->groupBy('product_variant_id');

        return [
            'locations' => $locations->map(fn (Location $l) => ['id' => $l->id, 'name' => $l->name, 'is_default' => $l->is_default])->values(),
            'variants' => $variants->map(function (ProductVariant $v) use ($locations, $levels) {
                $mine = ($levels[$v->id] ?? collect())->keyBy('location_id');
                $rows = $locations->map(function (Location $l) use ($mine) {
                    // A location the variant was never kept at has no level yet: nothing there.
                    $level = $mine->get($l->id);

                    return [
                        'location_id' => $l->id,
                        'on_hand' => $level === null ? 0.0 : (float) $level->on_hand,
                        'reserved' => $level === null ? 0.0 : (float) $level->reserved,
                        'available' => $level === null ? 0.0 : (float) $level->available(),
                    ];
                })->values();

                return [
                    'id' => $v->id,
                    'label' => $v->label(),
                    'sku' => $v->sku,
                    'is_active' => $v->is_active,
                    'track_stock' => $v->track_stock,
                    'low_stock_at' => $v->low_stock_at === null ? null : (float) $v->low_stock_at,
                    'unit' => ['name' => $v->unit->name, 'plural' => $v->unit->plural, 'allows_decimals' => $v->unit->allows_decimals],
                    'levels' => $rows,
                    'on_hand' => round($rows->sum('on_hand'), 3),
                    'reserved' => round($rows->sum('reserved'), 3),
                    'available' => round($rows->sum('available'), 3),
                ];
            })->values(),
        ];
    }
}
