<?php

namespace App\Enums;

enum UptimeStatus: string
{
    case Up = 'up';
    case Down = 'down';

    public function label(): string
    {
        return match ($this) {
            self::Up => 'Up',
            self::Down => 'Down',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Up => 'green',
            self::Down => 'red',
        };
    }
}
