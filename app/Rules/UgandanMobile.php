<?php

namespace App\Rules;

use App\Support\Phone;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** An MTN or Airtel Uganda number in any common shape (0772..., +256772..., 772...). */
class UgandanMobile implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! Phone::isValid(is_string($value) ? $value : null)) {
            $fail('Enter an MTN or Airtel Uganda number, like 0772 123 456.');
        }
    }
}
