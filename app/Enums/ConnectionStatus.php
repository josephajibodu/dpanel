<?php

namespace App\Enums;

enum ConnectionStatus: string
{
    case Unknown = 'unknown';
    case Successful = 'successful';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Unknown => 'Unknown',
            self::Successful => 'Connected',
            self::Failed => 'Connection Failed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Unknown => 'gray',
            self::Successful => 'green',
            self::Failed => 'red',
        };
    }
}
