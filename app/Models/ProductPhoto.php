<?php

namespace App\Models;

use App\Contracts\HasSnapshotSchema;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * A product photo: WebP at two sizes on the public disk, metadata stripped (App\Support\ProductImage).
 * The first in order is the product's cover.
 *
 * @property string $id
 * @property string $product_id
 * @property string|null $product_variant_id
 * @property string $path
 * @property string $thumb_path
 * @property int $width
 * @property int $height
 * @property int $size_bytes
 * @property int $position
 * @property string|null $source_url
 */
class ProductPhoto extends Model implements HasSnapshotSchema
{
    use HasUlids;

    /** Public: buyers' browsers load these straight from storage (and a CDN in production). */
    public const DISK = 'public';

    protected $fillable = [
        'product_id',
        'product_variant_id',
        'path',
        'thumb_path',
        'width',
        'height',
        'size_bytes',
        'position',
        'source_url',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'width' => 'integer',
            'height' => 'integer',
            'size_bytes' => 'integer',
            'position' => 'integer',
        ];
    }

    /** The files go with the row. */
    protected static function booted(): void
    {
        static::deleted(function (ProductPhoto $photo) {
            Storage::disk(self::DISK)->delete([$photo->path, $photo->thumb_path]);
        });
    }

    /** @return array<string, array<string, mixed>> */
    public static function snapshotSchema(): array
    {
        return [
            'path' => ['label' => 'Photo', 'type' => 'file'],
            'product_variant_id' => ['label' => 'Shows', 'type' => 'relation', 'resolve' => fn ($id) => ProductVariant::query()->with('optionValues.option')->find($id)?->label()],
            'width' => ['label' => 'Width (px)', 'type' => 'number'],
            'height' => ['label' => 'Height (px)', 'type' => 'number'],
            'size_bytes' => ['label' => 'Size (bytes)', 'type' => 'number'],
            'position' => ['label' => 'Position', 'type' => 'number'],
            'source_url' => ['label' => 'Copied from', 'type' => 'link'],
            'product_id' => ['hidden' => true],
            'thumb_path' => ['hidden' => true],
        ];
    }

    public function url(): string
    {
        return Storage::disk(self::DISK)->url($this->path);
    }

    public function thumbUrl(): string
    {
        return Storage::disk(self::DISK)->url($this->thumb_path);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
