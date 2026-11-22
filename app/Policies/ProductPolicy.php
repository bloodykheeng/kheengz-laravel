<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;

/**
 * A product is its shop's: whoever may see the shop sees it (reviewers too), and whoever may manage
 * the shop's catalogue changes it (ShopPolicy::manageCatalogue).
 */
class ProductPolicy
{
    public function __construct(private ShopPolicy $shops) {}

    public function view(User $user, Product $product): bool
    {
        return $this->shops->view($user, $product->shop);
    }

    public function update(User $user, Product $product): bool
    {
        return $this->shops->manageCatalogue($user, $product->shop);
    }

    /** Take a product down, or allow it again (D35): whoever decides on vendors and shops. */
    public function moderate(User $user, Product $product): bool
    {
        return $user->can('manage vendors');
    }
}
