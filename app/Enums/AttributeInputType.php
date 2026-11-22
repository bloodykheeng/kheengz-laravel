<?php

namespace App\Enums;

/**
 * How a product attribute is filled in (D32): a choice from its list (Colour: Red), a number in its
 * unit (Capacity: 20 L), or short text (Brand). docs/catalogue.md.
 *
 * Stored as the string value, mirrored in the Next app's services/types/catalogue.ts.
 */
enum AttributeInputType: string
{
    case List = 'List';
    case Number = 'Number';
    case Text = 'Text';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
