<?php

namespace App\Policies;

use App\Models\Category;
use App\Models\User;

/**
 * The catalogue's shared lists (categories, attributes, units): read by everyone who places products,
 * changed by whoever keeps the catalogue (`manage catalogue`, the Admin).
 */
class CategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('manage catalogue') || $user->can('run shops') || $user->can('view vendors');
    }

    public function view(User $user, Category $category): bool
    {
        return $this->viewAny($user);
    }
}
