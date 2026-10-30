<?php

namespace App\Enums;

enum ServerConnectionStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Failed = 'failed';
    case Removing = 'removing';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Opening',
            self::Active => 'Connected',
            self::Failed => 'Failed',
            self::Removing => 'Removing',
        };
    }
}
