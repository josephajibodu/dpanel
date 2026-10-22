<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * An absolute file path made of letters, numbers, dots, dashes, underscores
 * and slashes, with no `..` segments.
 */
class SafeAbsolutePath implements ValidationRule
{
    /**
     * @param  \Closure(string, ?string=): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $isSafe = is_string($value)
            && preg_match('#^/[A-Za-z0-9._\-/]+\z#', $value) === 1
            && ! in_array('..', explode('/', $value), true);

        if (! $isSafe) {
            $fail('The :attribute must be an absolute path such as /home/artisan/worker.log, using only letters, numbers, dots, dashes, underscores and slashes.');
        }
    }
}
