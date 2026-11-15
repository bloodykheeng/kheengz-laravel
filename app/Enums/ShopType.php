<?php

namespace App\Enums;

/**
 * What kind of shop it is (D24). Each type brings its own layout, attributes, units and defaults
 * (docs/plan-shop-identity-and-marketing.md §2); new types are added here, in code, because each has
 * real behaviour.
 *
 * Stored as the string value, mirrored in the Next app's services/types/kheengz.ts.
 */
enum ShopType: string
{
    case HouseholdAndHome = 'Household & home';
    case ElectronicsAndPhones = 'Electronics & phones';
    case GroceriesAndFreshProduce = 'Groceries & fresh produce';
    case FashionAndBeauty = 'Fashion & beauty';
    case HardwareAndBuilding = 'Hardware & building';
    case General = 'General';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
