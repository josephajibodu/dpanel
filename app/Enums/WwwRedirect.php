<?php

namespace App\Enums;

enum WwwRedirect: string
{
    case None = 'none';
    case FromWww = 'from_www';
    case ToWww = 'to_www';

    public function label(): string
    {
        return match ($this) {
            self::None => 'No redirect',
            self::FromWww => 'Redirect from www',
            self::ToWww => 'Redirect to www',
        };
    }
}
