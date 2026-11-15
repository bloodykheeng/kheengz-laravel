<?php

namespace App\Models;

use App\Contracts\HasSnapshotSchema;
use App\Enums\PhotoSource;
use App\Support\Phone;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * Someone who signs in: Kheengz's Admins and Staff, and the people who run a shop (Vendor).
 * Buyers are never users: they order with a phone number and no account (workspace D12, D26).
 * What a person may do in ONE shop is their `vendor_users` role (Owner / Staff), arriving with
 * the vendors module in Phase 1, never the account role.
 */
class User extends Authenticatable implements HasSnapshotSchema
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, HasUlids, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'username',
        'phone',
        'allow_notifications',
        'status',
        'password',
        'lastlogin',
        'terms_accepted_at',
        'photo_url',
        'photo_source',
        'photo_source_url',
        'timezone',
        'created_by',
        'updated_by',
    ];

    /** A deleted account's tokens and photo go with it. */
    protected static function booted(): void
    {
        static::deleting(function (User $user) {
            $user->tokens()->delete();
        });

        static::deleted(function (User $user) {
            $photo = $user->getRawOriginal('photo_url');

            if ($photo && ! str_starts_with($photo, 'http')) {
                Storage::disk('public')->delete(str_replace('/storage/', '', $photo));
            }
        });
    }

    /**
     * `password` and `remember_token` are absent on purpose: secrets never
     * reach the audit trail. LoggableTrait redacts them too; this is the
     * second lock.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function snapshotSchema(): array
    {
        return [
            'name' => ['label' => 'Name', 'type' => 'string'],
            'email' => ['label' => 'Email', 'type' => 'string'],
            'username' => ['label' => 'Username', 'type' => 'string'],
            'phone' => ['label' => 'Phone', 'type' => 'string'],
            // Not a column: a role lives in Spatie's pivot, so `getChanges()` is blind
            // to it and granting someone Admin would otherwise log as "nothing
            // changed". The `current` closure is what puts it in the diff, and reads
            // through the relation QUERY rather than the loaded relation, so a capture
            // taken straight after `syncRoles()` sees the new value and not a cached one.
            'role' => [
                'label' => 'Role',
                'type' => 'string',
                'current' => fn (self $model) => $model->roles()->value('name'),
            ],
            'allow_notifications' => ['label' => 'Allow notifications', 'type' => 'boolean'],
            'timezone' => ['label' => 'Timezone', 'type' => 'string'],
            'photo_url' => ['label' => 'Photo', 'type' => 'file'],
            'photo_source' => ['label' => 'Photo source', 'type' => 'text'],
            'photo_source_url' => ['label' => 'Photo copied from', 'type' => 'text'],
            'status' => ['label' => 'Status', 'type' => 'enum', 'enum' => [
                'Active' => 'Active',
                'Inactive' => 'Inactive',
            ]],
            // Noise on a diff: it moves on every login, not by anyone's decision.
            'lastlogin' => ['label' => 'Last login', 'type' => 'datetime', 'hidden' => true],
        ];
    }

    protected $hidden = [
        'password',
        'remember_token',
    ];

    /** @var list<string> */
    protected $appends = ['role'];

    /**
     * `roles` is loaded with every user, because `role` above is appended.
     *
     * An appended attribute is serialised EVERY time, so `getRoleAttribute()`
     * reading `$this->roles` makes the relation a hard dependency of rendering a
     * user rather than an optional include. Under `preventLazyLoading()` that is
     * an exception, so the dependency belongs on the model rather than on every
     * call site that returns users.
     *
     * **`createdBy` and `updatedBy` must never be added here.** They point back
     * at User, so defaulting them would recurse. They stay as explicit
     * `with()` / `load()` at the call sites that render them.
     *
     * @var list<string>
     */
    protected $with = ['roles'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'lastlogin' => 'datetime',
            'terms_accepted_at' => 'datetime',
            'allow_notifications' => 'boolean',
            'password' => 'hashed',
            'photo_source' => PhotoSource::class,
        ];
    }

    /**
     * The three columns a person can be reached and signed in by.
     *
     * One list, read by validation, sign-in and password reset, so the rule
     * "at least one of these" cannot drift between them.
     *
     * @var list<string>
     */
    public const CONTACT_COLUMNS = ['email', 'phone', 'username'];

    /**
     * The account a sign-in credential belongs to, by its shape.
     *
     * An email goes to the email column, a run of digits to the phone column
     * and anything else to the username, so a value that happens to sit in
     * the wrong column can never resolve to an account.
     */
    public static function findByCredential(?string $credential): ?self
    {
        $credential = trim((string) $credential);

        if ($credential === '') {
            return null;
        }

        if (filter_var($credential, FILTER_VALIDATE_EMAIL)) {
            return static::query()->with('roles')->firstWhere('email', $credential);
        }

        // A phone signs in however it is typed (0772 123 456, +256 772...), as it is stored.
        $digits = preg_replace('/\D/', '', $credential);

        if ($digits !== '' && preg_match('/^[+\d\s()-]+$/', $credential) && preg_match('/^\d{9,15}$/', $digits)) {
            return static::query()->with('roles')->firstWhere('phone', Phone::international($credential) ?? $digits);
        }

        return static::query()->with('roles')->firstWhere('username', $credential);
    }

    /** One role per account: UserController assigns exactly one, and syncRoles() replaces it. */
    public function getRoleAttribute(): string
    {
        return $this->getRoleNames()->first() ?? 'No Role';
    }

    public function getPhotoUrlAttribute(?string $value): ?string
    {
        // An External photo is an outside address, shown as it is; anything else is our file.
        if (! $value) {
            return null;
        }

        return str_starts_with($value, 'http') ? $value : asset('storage/'.$value);
    }

    public function scopeSearch(Builder $query, string $value): void
    {
        $query->where(function (Builder $q) use ($value) {
            $q->where('name', 'like', "%{$value}%")
                ->orWhere('email', 'like', "%{$value}%")
                ->orWhere('username', 'like', "%{$value}%")
                ->orWhere('phone', 'like', "%{$value}%");
        });
    }

    /**
     * Every permission on this account is registered for one guard: `web`.
     *
     * Spatie asks the model which guard its roles belong to and falls back to
     * `config('auth.defaults.guard')`, which `auth:<guard>` middleware rewrites
     * for the rest of the request. `auth:sanctum` sets it to `sanctum`, which
     * is not a guard in `config/auth.php`, so the lookup would fall through to
     * the provider's first guard by accident. Stating it here makes it a rule:
     * there is one set of roles and one set of permissions, and how an account
     * authenticated never changes what it may do.
     */
    public function guardName(): string
    {
        return 'web';
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
