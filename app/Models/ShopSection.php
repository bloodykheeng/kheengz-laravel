<?php

namespace App\Models;

use App\Contracts\HasSnapshotSchema;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Storage;

/**
 * A shop's own group of products, named as the vendor likes (D32). docs/catalogue.md.
 *
 * @property string $id
 * @property string $shop_id
 * @property string $name
 * @property string $slug
 * @property string|null $photo_path
 * @property int $position
 * @property bool $is_active
 * @property-read Shop $shop
 */
class ShopSection extends Model implements HasSnapshotSchema
{
    use HasUlids;

    /** Etsy allows 20; a menu longer than this stops helping buyers. */
    public const MAX_PER_SHOP = 30;

    protected $fillable = [
        'shop_id',
        'name',
        'slug',
        'photo_path',
        'position',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** The photo goes with the row. */
    protected static function booted(): void
    {
        static::deleted(function (ShopSection $section) {
            if ($section->photo_path !== null) {
                Storage::disk(ProductPhoto::DISK)->delete($section->photo_path);
            }
        });
    }

    /** @return array<string, array<string, mixed>> */
    public static function snapshotSchema(): array
    {
        return [
            'name' => ['label' => 'Name', 'type' => 'string'],
            'slug' => ['label' => 'Address name', 'type' => 'string'],
            'photo_path' => ['label' => 'Photo', 'type' => 'file'],
            'position' => ['label' => 'Position', 'type' => 'number'],
            'is_active' => ['label' => 'On', 'type' => 'boolean'],
            'shop_id' => ['hidden' => true],
        ];
    }

    public function photoUrl(): ?string
    {
        return $this->photo_path === null ? null : Storage::disk(ProductPhoto::DISK)->url($this->photo_path);
    }

    /** @return BelongsTo<Shop, $this> */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /** @return BelongsToMany<Product, $this> */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class)->withPivot('position')->withTimestamps();
    }
}
