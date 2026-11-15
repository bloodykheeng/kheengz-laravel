<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Vendor;

/**
 * Whose vendor is it? People who review vendors (`view vendors`, `manage vendors`) reach every
 * vendor; anyone else only the vendors they are a member of, and only an Owner changes one.
 */
class VendorPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view vendors') || $user->can('run shops');
    }

    public function view(User $user, Vendor $vendor): bool
    {
        return $user->can('view vendors') || $vendor->roleOf($user) !== null;
    }

    /** Details, documents, members, new shops: the Owner's. */
    public function own(User $user, Vendor $vendor): bool
    {
        return $user->can('run shops') && $vendor->isOwnedBy($user);
    }

    /** Approve, reject, suspend, reinstate. */
    public function review(User $user, Vendor $vendor): bool
    {
        return $user->can('manage vendors');
    }
}
