<?php

namespace App\Rules;

use App\Support\Phone;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A phone number from any country, for contact numbers (a buyer abroad, a vendor's
 * account). Ugandan numbers may be typed the local way (0772 123 456); anything
 * else needs its country code. Payment numbers use UgandanMobile instead, because
 * Mobile Money only prompts MTN and Airtel Uganda.
 */
class PhoneNumber implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (Phone::international(is_string($value) ? $value : null) === null) {
            $fail('Enter a phone number. A number outside Uganda needs its country code, like +44 7911 123456.');
        }
    }
}
