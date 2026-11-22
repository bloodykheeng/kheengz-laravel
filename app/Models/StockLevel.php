<?php

namespace App\Models;

use App\Support\Quantity;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How many of a variant are at one location, and how many are held for orders (D5, D33). Changed only
 * through App\Services\StockLedger. docs/stock.md.
 *
 * @property string $id
 * @property string $product_variant_id
 * @property string $location_id
 * @property string $on_hand
 * @property string $reserved
 * @property-read ProductVariant $variant
 * @property-read Location $location
 */
class StockLevel extends Model
{
    use HasUlids;

    protected $fillable = ['product_variant_id', 'location_id', 'on_hand', 'reserved'];

    protected function casts(): array
    {
        return [
            'on_hand' => 'decimal:3',
            'reserved' => 'decimal:3',
        ];
    }

    /** On hand and not held: what can still be sold. */
    public function available(): string
    {
        return Quantity::fromMilli(Quantity::toMilli($this->on_hand) - Quantity::toMilli($this->reserved));
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
