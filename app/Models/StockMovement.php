<?php

namespace App\Models;

use App\Enums\StockMovementReason;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One change to a stock level, with the figures after it. Written once by StockLedger, never edited:
 * a mistake is corrected by another movement. docs/stock.md.
 *
 * @property string $id
 * @property string $product_variant_id
 * @property string $location_id
 * @property StockMovementReason $reason
 * @property string $on_hand_change
 * @property string $reserved_change
 * @property string $on_hand_after
 * @property string $reserved_after
 * @property string|null $note
 * @property string|null $source_type
 * @property string|null $source_id
 * @property-read ProductVariant $variant
 * @property-read Location $location
 * @property-read User|null $createdBy
 */
class StockMovement extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'product_variant_id',
        'location_id',
        'reason',
        'on_hand_change',
        'reserved_change',
        'on_hand_after',
        'reserved_after',
        'note',
        'source_type',
        'source_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'reason' => StockMovementReason::class,
            'on_hand_change' => 'decimal:3',
            'reserved_change' => 'decimal:3',
            'on_hand_after' => 'decimal:3',
            'reserved_after' => 'decimal:3',
        ];
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

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
