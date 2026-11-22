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

    /**
     * The units this kind of shop sells by, offered first on its product form (plan-shop-identity §2).
     * Names from the units table; the Admin keeps that list, so a name missing there is skipped.
     *
     * @return list<string>
     */
    public function suggestedUnits(): array
    {
        return match ($this) {
            self::HouseholdAndHome => ['piece', 'dozen', 'bale', 'set'],
            self::HardwareAndBuilding => ['piece', 'metre', 'bag', 'kg', 'roll', 'sheet'],
            self::GroceriesAndFreshProduce => ['kg', 'bunch', 'heap', 'tray', 'crate', 'piece'],
            self::FashionAndBeauty, self::ElectronicsAndPhones => ['piece', 'set'],
            self::General => ['piece', 'dozen', 'kg'],
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
