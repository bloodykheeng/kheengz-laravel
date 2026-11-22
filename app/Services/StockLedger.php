<?php

namespace App\Services;

use App\Enums\StockMovementReason;
use App\Exceptions\InsufficientStock;
use App\Models\Location;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\User;
use App\Notifications\StockRunningLow;
use App\Support\Quantity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;

/**
 * The only way stock changes (D5, D33). Each change locks its level row, checks that nothing goes below
 * zero and that what is held never exceeds what is on hand, saves the new figures and writes a movement
 * with the figures after it, in one transaction. Holding an order's items is one guarded step, so two
 * buyers can never both get the last one. A variant whose stock is not counted (track_stock off) is
 * never held or refused. docs/stock.md.
 */
class StockLedger
{
    // ── A shop's people ────────────────────────────────────────────────────────

    public function receive(ProductVariant $variant, Location $location, string $quantity, ?string $note, User $by): StockMovement
    {
        return $this->apply($variant, $location, StockMovementReason::Received, Quantity::toMilli($quantity), 0, $note, null, $by);
    }

    public function returned(ProductVariant $variant, Location $location, string $quantity, ?string $note, User $by): StockMovement
    {
        return $this->apply($variant, $location, StockMovementReason::Returned, Quantity::toMilli($quantity), 0, $note, null, $by);
    }

    public function damaged(ProductVariant $variant, Location $location, string $quantity, ?string $note, User $by): StockMovement
    {
        return $this->apply($variant, $location, StockMovementReason::Damaged, -Quantity::toMilli($quantity), 0, $note, null, $by);
    }

    /** What was counted on the shelf becomes the figure; the difference is the movement. */
    public function count(ProductVariant $variant, Location $location, string $counted, ?string $note, User $by): StockMovement
    {
        return $this->apply($variant, $location, StockMovementReason::Counted, null, 0, $note, null, $by, setOnHandTo: Quantity::toMilli($counted));
    }

    /**
     * From one location to another: two movements, each naming the other side.
     *
     * @return array{0: StockMovement, 1: StockMovement}
     */
    public function move(ProductVariant $variant, Location $from, Location $to, string $quantity, ?string $note, User $by): array
    {
        if ($from->id === $to->id) {
            throw new InvalidArgumentException('A move needs two different locations.');
        }
        $milli = Quantity::toMilli($quantity);

        return DB::transaction(fn () => [
            $this->apply($variant, $from, StockMovementReason::MovedOut, -$milli, 0, $note, ['location', $to->id], $by),
            $this->apply($variant, $to, StockMovementReason::MovedIn, $milli, 0, $note, ['location', $from->id], $by),
        ]);
    }

    // ── Orders (step 5) ────────────────────────────────────────────────────────

    /** Hold an order's items the moment it is placed (D33). Throws InsufficientStock when they are gone. */
    public function hold(ProductVariant $variant, Location $location, string $quantity, string $sourceType, string $sourceId): ?StockMovement
    {
        if (! $variant->track_stock) {
            return null;
        }

        return $this->apply($variant, $location, StockMovementReason::Held, 0, Quantity::toMilli($quantity), null, [$sourceType, $sourceId], null);
    }

    /** Free a hold: payment failed or timed out, or the order was cancelled. */
    public function release(ProductVariant $variant, Location $location, string $quantity, string $sourceType, string $sourceId): ?StockMovement
    {
        if (! $variant->track_stock) {
            return null;
        }

        return $this->apply($variant, $location, StockMovementReason::Released, 0, -Quantity::toMilli($quantity), null, [$sourceType, $sourceId], null);
    }

    /** A held item leaves the shop: off the shelf and out of the hold together. */
    public function sell(ProductVariant $variant, Location $location, string $quantity, string $sourceType, string $sourceId): ?StockMovement
    {
        if (! $variant->track_stock) {
            return null;
        }
        $milli = Quantity::toMilli($quantity);

        return $this->apply($variant, $location, StockMovementReason::SoldOnline, -$milli, -$milli, null, [$sourceType, $sourceId], null);
    }

    // ── Reading ────────────────────────────────────────────────────────────────

    /** On hand minus held, over every location of the variant's shop, in thousandths. */
    public function availableMilli(ProductVariant $variant): int
    {
        $total = StockLevel::query()->where('product_variant_id', $variant->id)->selectRaw('coalesce(sum(on_hand - reserved), 0) as available')->value('available');

        return Quantity::toMilli((string) $total);
    }

    // ── The one place a level changes ──────────────────────────────────────────

    /**
     * @param  int|null  $onHandChange  thousandths; null with `$setOnHandTo` for a count
     * @param  array{0: string, 1: string}|null  $source  [type, id]
     */
    private function apply(
        ProductVariant $variant,
        Location $location,
        StockMovementReason $reason,
        ?int $onHandChange,
        int $reservedChange,
        ?string $note,
        ?array $source,
        ?User $by,
        ?int $setOnHandTo = null,
    ): StockMovement {
        $before = $variant->track_stock ? $this->availableMilli($variant) : null;

        $movement = DB::transaction(function () use ($variant, $location, $reason, $onHandChange, $reservedChange, $note, $source, $by, $setOnHandTo) {
            // Made once (the unique key settles two first movements at the same moment), then locked.
            StockLevel::query()->insertOrIgnore([
                'id' => (string) str()->ulid(),
                'product_variant_id' => $variant->id,
                'location_id' => $location->id,
                'on_hand' => 0,
                'reserved' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $level = StockLevel::query()->where('product_variant_id', $variant->id)->where('location_id', $location->id)->lockForUpdate()->firstOrFail();

            $onHand = Quantity::toMilli($level->on_hand);
            $reserved = Quantity::toMilli($level->reserved);
            $onHandChange ??= ($setOnHandTo ?? $onHand) - $onHand;
            $newOnHand = $onHand + $onHandChange;
            $newReserved = max(0, $reserved + $reservedChange);
            $this->guard($reason, $newOnHand, $newReserved, $onHand - $reserved, $reserved);

            $level->update(['on_hand' => Quantity::fromMilli($newOnHand), 'reserved' => Quantity::fromMilli($newReserved)]);

            return StockMovement::query()->create([
                'product_variant_id' => $variant->id,
                'location_id' => $location->id,
                'reason' => $reason,
                'on_hand_change' => Quantity::fromMilli($onHandChange),
                'reserved_change' => Quantity::fromMilli($newReserved - $reserved),
                'on_hand_after' => Quantity::fromMilli($newOnHand),
                'reserved_after' => Quantity::fromMilli($newReserved),
                'note' => $note,
                'source_type' => $source[0] ?? null,
                'source_id' => $source[1] ?? null,
                'created_by' => $by?->id,
            ]);
        });

        if ($before !== null) {
            DB::afterCommit(fn () => $this->warnIfRunningLow($variant, $before));
        }

        return $movement;
    }

    /** Nothing below zero, and never more held than is on hand. */
    private function guard(StockMovementReason $reason, int $newOnHand, int $newReserved, int $available, int $reserved): void
    {
        if ($newOnHand >= 0 && $newReserved <= $newOnHand) {
            return;
        }

        $free = Quantity::fromMilli(max(0, $available));
        throw new InsufficientStock($free, match ($reason) {
            StockMovementReason::Counted => 'Orders already hold '.Quantity::fromMilli($reserved).'. Count again, or cancel those orders first.',
            StockMovementReason::Held => "Only {$free} left.",
            default => "Only {$free} free here: the rest is not there or is held for orders.",
        });
    }

    /** Tell the shop once as what is free falls to the variant's line, and again if it runs out. */
    private function warnIfRunningLow(ProductVariant $variant, int $before): void
    {
        $line = $variant->low_stock_at === null ? 0 : Quantity::toMilli($variant->low_stock_at);
        $after = $this->availableMilli($variant);
        $fellToLine = $before > $line && $after <= $line;
        $ranOut = $before > 0 && $after <= 0;
        if (! $fellToLine && ! $ranOut) {
            return;
        }

        $variant->loadMissing(['product.shop.vendor', 'optionValues.option']);
        $shop = $variant->product->shop;
        $people = $shop->vendor->members()->wherePivot('role', 'Owner')->get()->merge($shop->staff()->get())->unique('id');
        Notification::send($people, new StockRunningLow($variant, Quantity::fromMilli($after)));
    }
}
