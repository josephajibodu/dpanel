<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Workers and cron jobs may only run as the server user FlitOps creates
 * during provisioning, never as root or another system account.
 */
class AllowedProcessUser implements ValidationRule
{
    /**
     * @return list<string>
     */
    public static function users(): array
    {
        return [config('server.user')];
    }

    /**
     * @param  \Closure(string, ?string=): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! in_array($value, self::users(), true)) {
            $fail('The user must be "'.implode('" or "', self::users()).'". Running as root or other system users is not allowed.');
        }
    }
}
