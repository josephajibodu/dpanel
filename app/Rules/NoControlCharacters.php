<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Rejects line breaks and other control characters. Values such as worker
 * commands are written into supervisor and cron files one setting per line,
 * so a line break would let a value add settings of its own.
 */
class NoControlCharacters implements ValidationRule
{
    /**
     * @param  \Closure(string, ?string=): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match('/\p{Cc}/u', $value) !== 0) {
            $fail('The :attribute cannot contain line breaks, tabs or other control characters.');
        }
    }
}
