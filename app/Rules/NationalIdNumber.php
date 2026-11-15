<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A Ugandan National Identification Number (NIN) as printed on the national ID: 14 letters and
 * digits starting CM (male) or CF (female). Callers upper-case and strip spaces before validating
 * (VendorApplicationController::normalise), so "cm 9012..." from a phone keyboard passes.
 */
class NationalIdNumber implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! preg_match('/^C[MF][A-Z0-9]{12}$/', $value)) {
            $fail('Enter the 14-character National ID Number (NIN) from the ID card, starting CM or CF.');
        }
    }
}
