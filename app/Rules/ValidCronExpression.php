<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidCronExpression implements ValidationRule
{
    // Each field: *, step (every-n), number, range, range+step, or comma-separated list thereof.
    private const FIELD_PATTERN = '(\*|(\*\/[0-9]+)|([0-9]+(-[0-9]+)?(\/[0-9]+)?(,[0-9]+(-[0-9]+)?(\/[0-9]+)?)*))';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $pattern = '/^'.implode('\s+', array_fill(0, 5, self::FIELD_PATTERN)).'$/';

        if (! preg_match($pattern, trim((string) $value))) {
            $fail('The cron expression must have exactly 5 fields (minute hour day month weekday) with valid cron syntax.');
        }
    }
}
