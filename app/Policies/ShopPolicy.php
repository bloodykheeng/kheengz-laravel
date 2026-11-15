<?php

namespace App\Policies;

use App\Models\Shop;
use App\Models\User;

/**
 * Whose shop is it? Reviewers reach every shop; a vendor's Owner every shop of that vendor; Staff
 * only the shops they were given (Shop::scopeRunBy). Sending for review and closing are the Owner's.
 */
class ShopPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view vendors') || $user->can('run shops');
    }

    public function view(User $user, Shop $shop): bool
    {
        return $user->can('view vendors') || $shop->isRunBy($user);
    }

    /**
     * Change its details and locations. Not while the Admin is reviewing it (the version reviewed is
     * the version approved), and not once suspended or closed.
     */
    public function update(User $user, Shop $shop): bool
    {
        return $user->can('run shops') && $shop->status->isEditableByVendor() && $shop->isRunBy($user);
    }

    /** Send for review, close: the vendor's Owner. */
    public function own(User $user, Shop $shop): bool
    {
        return $user->can('run shops') && $shop->vendor->isOwnedBy($user);
    }

    public function review(User $user, Shop $shop): bool
    {
        return $user->can('manage vendors');
    }
}
