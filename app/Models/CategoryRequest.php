<?php

namespace App\Models;

use App\Contracts\HasSnapshotSchema;
use App\Enums\CategoryRequestStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A vendor's request for a category the tree lacks (D32). docs/catalogue.md.
 *
 * @property string $id
 * @property string $shop_id
 * @property string $name
 * @property string|null $parent_id
 * @property string|null $note
 * @property CategoryRequestStatus $status
 * @property string|null $category_id
 * @property string|null $answer
 * @property string|null $created_by
 * @property-read Shop $shop
 * @property-read Category|null $parent
 * @property-read Category|null $category
 */
class CategoryRequest extends Model implements HasSnapshotSchema
{
    use HasUlids;

    protected $fillable = [
        'shop_id',
        'name',
        'parent_id',
        'note',
        'status',
        'category_id',
        'answer',
        'decided_at',
        'decided_by',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => CategoryRequestStatus::class,
            'decided_at' => 'datetime',
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public static function snapshotSchema(): array
    {
        return [
            'name' => ['label' => 'Asked for', 'type' => 'string'],
            'parent_id' => ['label' => 'Inside', 'type' => 'relation', 'resolve' => fn ($id) => Category::query()->whereKey($id)->value('name')],
            'note' => ['label' => 'Note', 'type' => 'text'],
            'status' => ['label' => 'Status', 'type' => 'string'],
            'category_id' => ['label' => 'Category', 'type' => 'relation', 'resolve' => fn ($id) => Category::query()->whereKey($id)->value('name')],
            'answer' => ['label' => 'Answer', 'type' => 'text'],
            'shop_id' => ['hidden' => true],
            'decided_at' => ['hidden' => true],
            'decided_by' => ['hidden' => true],
        ];
    }

    /** @return BelongsTo<Shop, $this> */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /** @return BelongsTo<Category, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
