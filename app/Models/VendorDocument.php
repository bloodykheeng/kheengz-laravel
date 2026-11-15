<?php

namespace App\Models;

use App\Contracts\HasSnapshotSchema;
use App\Enums\VendorDocumentType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * A paper a vendor gave for approval. The file is on the private disk: served only through
 * VendorDocumentController, to the vendor's Owner and to people who review vendors.
 *
 * @property string $id
 * @property string $vendor_id
 * @property VendorDocumentType $type
 * @property string $file_path
 * @property string $original_name
 * @property string $mime_type
 * @property int $size_bytes
 */
class VendorDocument extends Model implements HasSnapshotSchema
{
    use HasUlids;

    /** The private disk (storage/app/private): never reachable by a public URL. */
    public const DISK = 'local';

    protected $fillable = [
        'vendor_id',
        'type',
        'file_path',
        'original_name',
        'mime_type',
        'size_bytes',
        'checked_at',
        'checked_by',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'type' => VendorDocumentType::class,
            'size_bytes' => 'integer',
            'checked_at' => 'datetime',
        ];
    }

    /** The file goes with the row. */
    protected static function booted(): void
    {
        static::deleted(function (VendorDocument $document) {
            Storage::disk(self::DISK)->delete($document->file_path);
        });
    }

    /** @return array<string, array<string, mixed>> */
    public static function snapshotSchema(): array
    {
        return [
            'type' => ['label' => 'Document', 'type' => 'string'],
            'original_name' => ['label' => 'File name', 'type' => 'string'],
            'mime_type' => ['label' => 'File type', 'type' => 'string'],
            'size_bytes' => ['label' => 'Size (bytes)', 'type' => 'integer'],
        ];
    }

    /** @return BelongsTo<Vendor, $this> */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /** @return BelongsTo<User, $this> */
    public function checkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_by');
    }
}
