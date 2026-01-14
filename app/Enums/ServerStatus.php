<?php

namespace App\Enums;

enum ServerStatus: string
{
    case Pending = 'pending';
    case Creating = 'creating';
    case Provisioning = 'provisioning';
    case Active = 'active';
    case Error = 'error';
    case Deleting = 'deleting';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Creating => 'Creating',
            self::Provisioning => 'Provisioning',
            self::Active => 'Active',
            self::Error => 'Error',
            self::Deleting => 'Deleting',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Creating => 'blue',
            self::Provisioning => 'yellow',
            self::Active => 'green',
            self::Error => 'red',
            self::Deleting => 'orange',
        };
    }
}
