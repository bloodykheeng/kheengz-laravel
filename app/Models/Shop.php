<?php

namespace App\Models;

use App\Contracts\HasSnapshotSchema;
use App\Enums\ShopStatus;
use App\Enums\ShopType;
use App\Enums\VendorMemberRole;
use App\Enums\VendorStatus;
use Database\Factories\ShopFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A shop of a vendor, approved on its own before it sells (D31). docs/vendors-and-shops.md.
 *
 * @property string $id
 * @property string $vendor_id
 * @property string $name
 * @property string $slug
 * @property ShopType $shop_type
 * @property ShopStatus $status
 * @property string|null $status_reason
 * @property int|null $commission_bps
 * @property int|null $debt_limit_amount
 * @property int|null $debt_limit_days
 * @property-read Vendor $vendor
 */
class Shop extends Model implements HasSnapshotSchema
{
    /** @use HasFactory<ShopFactory> */
    use HasFactory, HasUlids;

    /**
     * Address names no shop may take: they are the platform's own hosts and pages, or they would
     * pass for the platform (a phishing shop at `admin.kheengz.com`).
     */
    public const RESERVED_SLUGS = [
        'www', 'api', 'app', 'admin', 'dashboard', 'mail', 'email', 'smtp', 'ftp', 'cdn', 'static', 'assets',
        'media', 'files', 'storage', 'help', 'support', 'status', 'blog', 'shop', 'shops', 'store', 'stores',
        'login', 'signin', 'register', 'signup', 'account', 'accounts', 'billing', 'pay', 'payment', 'payments',
        'checkout', 'cart', 'orders', 'kheengz', 'marketplace', 'sell', 'seller', 'sellers', 'vendor', 'vendors',
        'reverb', 'ws', 'test', 'staging', 'dev', 'demo',
    ];

    protected $fillable = [
        'vendor_id',
        'name',
        'slug',
        'shop_type',
        'description',
        'phone',
        'email',
        'status',
        'status_reason',
        'approved_at',
        'approved_by',
        'payout_destination_id',
        'commission_bps',
        'debt_limit_amount',
        'debt_limit_days',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'shop_type' => ShopType::class,
            'status' => ShopStatus::class,
            'approved_at' => 'datetime',
            'commission_bps' => 'integer',
            'debt_limit_amount' => 'integer',
            'debt_limit_days' => 'integer',
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public static function snapshotSchema(): array
    {
        return [
            'name' => ['label' => 'Name', 'type' => 'string'],
            'slug' => ['label' => 'Address name', 'type' => 'string'],
            'shop_type' => ['label' => 'Shop type', 'type' => 'string'],
            'description' => ['label' => 'Description', 'type' => 'string'],
            'phone' => ['label' => 'Phone', 'type' => 'string'],
            'email' => ['label' => 'Email', 'type' => 'string'],
            'status' => ['label' => 'Status', 'type' => 'string'],
            'status_reason' => ['label' => 'Reason', 'type' => 'string'],
            'commission_bps' => ['label' => 'Commission (basis points)', 'type' => 'integer'],
            'debt_limit_amount' => ['label' => 'Debt limit (UGX)', 'type' => 'integer'],
            'debt_limit_days' => ['label' => 'Debt limit (days)', 'type' => 'integer'],
        ];
    }

    /** Where buyers find it: `homenest.kheengz.com` (config app.shops_domain, D26). */
    public function address(): string
    {
        return $this->slug.'.'.config('app.shops_domain');
    }

    /** Selling now: an Active shop of an Approved vendor. */
    public function isSelling(): bool
    {
        return $this->status === ShopStatus::Active && $this->vendor->status === VendorStatus::Approved;
    }

    /**
     * Shops this account may run: every shop of a vendor they own, and the shops they were given as
     * Staff. Platform people (Admin, Staff) reach shops through `manage vendors`, not this.
     *
     * @param  Builder<Shop>  $query
     * @return Builder<Shop>
     */
    public function scopeRunBy(Builder $query, User $user): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->whereIn('vendor_id', fn ($owned) => $owned->select('vendor_id')->from('vendor_users')
                ->where('user_id', $user->id)->where('role', VendorMemberRole::Owner->value))
            ->orWhereIn('id', fn ($given) => $given->select('shop_id')->from('shop_user')->where('user_id', $user->id)));
    }

    public function isRunBy(User $user): bool
    {
        return self::query()->runBy($user)->whereKey($this->id)->exists();
    }

    /** @return BelongsTo<Vendor, $this> */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * Staff given this shop (Owners run every shop of their vendor and are not listed).
     *
     * @return BelongsToMany<User, $this>
     */
    public function staff(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    /** @return BelongsTo<PayoutDestination, $this> */
    public function payoutDestination(): BelongsTo
    {
        return $this->belongsTo(PayoutDestination::class);
    }

    /** @return HasMany<Location, $this> */
    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }

    /** @return HasMany<ShopReview, $this> */
    public function reviews(): HasMany
    {
        return $this->hasMany(ShopReview::class);
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
