<?php

namespace App\Models;

use App\Contracts\HasSnapshotSchema;
use App\Enums\MobileNetwork;
use App\Enums\PayoutDestinationStatus;
use App\Enums\PayoutMethod;
use App\Exceptions\RecordInUseException;
use App\Support\Phone;
use Database\Factories\PayoutDestinationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Where a vendor's money goes (D12). Never edited: a change is a new one (PayoutDestinations).
 *
 * @property string $id
 * @property string $vendor_id
 * @property PayoutMethod $method
 * @property MobileNetwork|null $network Set for Mobile Money (PayoutDestinations::add)
 * @property string|null $bank_code
 * @property string|null $bank_name
 * @property string $account_number
 * @property string $account_name
 * @property string|null $checked_name
 * @property PayoutDestinationStatus $status
 * @property string|null $status_reason
 * @property-read Vendor $vendor
 */
class PayoutDestination extends Model implements HasSnapshotSchema
{
    /** @use HasFactory<PayoutDestinationFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'vendor_id',
        'method',
        'network',
        'bank_code',
        'bank_name',
        'account_number',
        'account_name',
        'checked_name',
        'status',
        'status_reason',
        'reviewed_at',
        'reviewed_by',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'method' => PayoutMethod::class,
            'network' => MobileNetwork::class,
            'status' => PayoutDestinationStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }

    /** A destination a shop is paid to stays until the shop picks another. */
    protected static function booted(): void
    {
        static::deleting(function (PayoutDestination $destination) {
            if ($destination->shops()->exists()) {
                throw new RecordInUseException('A shop is paid to this. Choose another payout destination for it first.');
            }
        });
    }

    /** "MTN Mobile Money · 0772 123 456" or "Equity Bank · 60001256421", for screens and SMS. */
    public function label(): string
    {
        return $this->method === PayoutMethod::MobileMoney
            ? $this->network->value.' Mobile Money · '.Phone::local($this->account_number)
            : $this->bank_name.' · '.$this->account_number;
    }

    /** @return array<string, array<string, mixed>> */
    public static function snapshotSchema(): array
    {
        return [
            'method' => ['label' => 'Method', 'type' => 'string'],
            'network' => ['label' => 'Network', 'type' => 'string'],
            'bank_name' => ['label' => 'Bank', 'type' => 'string'],
            'account_number' => ['label' => 'Account number', 'type' => 'string'],
            'account_name' => ['label' => 'Account name', 'type' => 'string'],
            'checked_name' => ['label' => 'Name the bank holds', 'type' => 'string'],
            'status' => ['label' => 'Status', 'type' => 'string'],
            'status_reason' => ['label' => 'Reason', 'type' => 'string'],
        ];
    }

    /** @return BelongsTo<Vendor, $this> */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /** @return HasMany<Shop, $this> */
    public function shops(): HasMany
    {
        return $this->hasMany(Shop::class);
    }

    /** @return BelongsTo<User, $this> */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
