<?php

namespace App\Rules;

use App\Models\Shop;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A shop's address name (`homenest` in homenest.kheengz.com): 3 to 40 lowercase letters, digits and
 * single hyphens, not starting or ending with one, not one of the platform's own names
 * (Shop::RESERVED_SLUGS), and not taken by another shop. Uniqueness is checked here so the message
 * can suggest what to do; the database's unique index still guards the race.
 */
class ShopSlug implements ValidationRule
{
    public function __construct(private ?string $ignoreShopId = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! preg_match('/^[a-z0-9](?:[a-z0-9]|-(?=[a-z0-9])){2,39}$/', $value)) {
            $fail('Use 3 to 40 small letters, digits and hyphens, like homenest or home-nest.');

            return;
        }

        if (in_array($value, Shop::RESERVED_SLUGS, true)) {
            $fail("{$value} is kept for the platform. Choose another address name.");

            return;
        }

        $taken = Shop::query()->where('slug', $value)->when($this->ignoreShopId, fn ($q) => $q->whereKeyNot($this->ignoreShopId))->exists();
        if ($taken) {
            $fail("{$value} is taken. Try adding your area, like {$value}-ntinda.");
        }
    }
}
