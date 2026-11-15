<?php

namespace App\Models;

use App\Enums\ShopReviewDecision;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One step in a shop's history. Written once by ShopReviewer, never edited, so it has no
 * updated_at and is not audited separately: the shop's own audit entry covers the same act.
 *
 * @property ShopReviewDecision $decision
 * @property string|null $reason
 */
class ShopReview extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $fillable = ['shop_id', 'decision', 'reason', 'created_by'];

    protected function casts(): array
    {
        return ['decision' => ShopReviewDecision::class];
    }

    /** @return BelongsTo<Shop, $this> */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
