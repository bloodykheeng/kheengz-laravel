<?php

namespace App\Models;

use App\Contracts\HasSnapshotSchema;
use App\Enums\VendorKind;
use App\Enums\VendorMemberRole;
use App\Enums\VendorStatus;
use Database\Factories\VendorFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The person or business we verify once and who runs one or more shops (D31, D35).
 *
 * @property string $id
 * @property string $name
 * @property VendorKind $kind
 * @property string $national_id_number
 * @property string|null $registration_number
 * @property string $phone
 * @property string|null $email
 * @property VendorStatus $status
 * @property string|null $status_reason
 * @property-read VendorUser|null $membership Set on a user's vendors (User::vendors)
 */
class Vendor extends Model implements HasSnapshotSchema
{
    /** @use HasFactory<VendorFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'name',
        'kind',
        'national_id_number',
        'registration_number',
        'phone',
        'email',
        'address',
        'status',
        'status_reason',
        'approved_at',
        'approved_by',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'kind' => VendorKind::class,
            'status' => VendorStatus::class,
            'approved_at' => 'datetime',
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public static function snapshotSchema(): array
    {
        return [
            'name' => ['label' => 'Name', 'type' => 'string'],
            'kind' => ['label' => 'Kind', 'type' => 'string'],
            'national_id_number' => ['label' => 'National ID number', 'type' => 'string'],
            'registration_number' => ['label' => 'Registration number', 'type' => 'string'],
            'phone' => ['label' => 'Phone', 'type' => 'string'],
            'email' => ['label' => 'Email', 'type' => 'string'],
            'address' => ['label' => 'Address', 'type' => 'string'],
            'status' => ['label' => 'Status', 'type' => 'string'],
            'status_reason' => ['label' => 'Reason', 'type' => 'string'],
        ];
    }

    public function isApproved(): bool
    {
        return $this->status === VendorStatus::Approved;
    }

    /** The member's place in this vendor, or null when they are not one. */
    public function roleOf(User $user): ?VendorMemberRole
    {
        // The membership row itself: read through the members relation, `role` would be the User
        // model's own accessor (the account role), not the place in this vendor.
        return VendorUser::query()->where('vendor_id', $this->id)->where('user_id', $user->id)->first()?->role;
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->roleOf($user) === VendorMemberRole::Owner;
    }

    /** @return BelongsToMany<User, $this, VendorUser, 'membership'> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'vendor_users')->using(VendorUser::class)->as('membership')->withPivot('role', 'created_by')->withTimestamps();
    }

    /** @return HasMany<Shop, $this> */
    public function shops(): HasMany
    {
        return $this->hasMany(Shop::class);
    }

    /** @return HasMany<PayoutDestination, $this> */
    public function payoutDestinations(): HasMany
    {
        return $this->hasMany(PayoutDestination::class);
    }

    /** @return HasMany<VendorDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(VendorDocument::class);
    }

    /** @return BelongsTo<User, $this> */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
