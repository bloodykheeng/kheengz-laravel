<?php

namespace App\Models;

use App\Enums\VendorMemberRole;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * A membership: someone's place in a vendor (D26). Reached as `$user->membership` on a vendor's
 * members and as `$vendor->membership` on a user's vendors, with the role as an enum.
 *
 * @property string $vendor_id
 * @property string $user_id
 * @property VendorMemberRole $role
 * @property string|null $created_by
 */
class VendorUser extends Pivot
{
    protected $table = 'vendor_users';

    protected function casts(): array
    {
        return ['role' => VendorMemberRole::class];
    }
}
