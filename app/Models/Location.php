<?php

namespace App\Models;

use App\Contracts\HasSnapshotSchema;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where a shop keeps stock and sends orders from (D5). One is the default: orders ship from it
 * unless the shop picks another.
 *
 * @property string $id
 * @property string $shop_id
 * @property string $name
 * @property bool $is_default
 */
class Location extends Model implements HasSnapshotSchema
{
    use HasUlids;

    protected $fillable = [
        'shop_id',
        'name',
        'address',
        'area',
        'phone',
        'opening_hours',
        'is_default',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    /** @return array<string, array<string, mixed>> */
    public static function snapshotSchema(): array
    {
        return [
            'name' => ['label' => 'Name', 'type' => 'string'],
            'address' => ['label' => 'Address', 'type' => 'string'],
            'area' => ['label' => 'Area', 'type' => 'string'],
            'phone' => ['label' => 'Phone', 'type' => 'string'],
            'opening_hours' => ['label' => 'Opening hours', 'type' => 'string'],
            'is_default' => ['label' => 'Default', 'type' => 'boolean'],
        ];
    }

    /** @return BelongsTo<Shop, $this> */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }
}
