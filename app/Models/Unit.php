<?php

namespace App\Models;

use App\Contracts\HasSnapshotSchema;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A unit of sale: piece, dozen, kg, metre (D17). The Admin keeps the list; a shop type suggests its
 * own first (ShopType::suggestedUnits). docs/catalogue.md.
 *
 * @property string $id
 * @property string $name
 * @property string $plural
 * @property bool $allows_decimals
 * @property int $position
 * @property bool $is_active
 */
class Unit extends Model implements HasSnapshotSchema
{
    use HasUlids;

    protected $fillable = [
        'name',
        'plural',
        'allows_decimals',
        'position',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'allows_decimals' => 'boolean',
            'position' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public static function snapshotSchema(): array
    {
        return [
            'name' => ['label' => 'Name', 'type' => 'string'],
            'plural' => ['label' => 'Plural', 'type' => 'string'],
            'allows_decimals' => ['label' => 'Takes decimals', 'type' => 'boolean'],
            'position' => ['label' => 'Position', 'type' => 'number'],
            'is_active' => ['label' => 'On', 'type' => 'boolean'],
        ];
    }
}
