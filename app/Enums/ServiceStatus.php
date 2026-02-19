<?php

namespace App\Enums;

enum ServiceStatus: string
{
    case Pending = 'pending';
    case Installing = 'installing';
    case Active = 'active';
    case Inactive = 'inactive';
    case Failed = 'failed';
    case Enabled = 'enabled';
    case Disabled = 'disabled';
    case Reloading = 'reloading';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Installing => 'Installing',
            self::Active => 'Active',
            self::Inactive => 'Inactive',
            self::Failed => 'Failed',
            self::Enabled => 'Enabled',
            self::Disabled => 'Disabled',
            self::Reloading => 'Reloading',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Installing => 'blue',
            self::Active => 'green',
            self::Inactive => 'yellow',
            self::Failed => 'red',
            self::Enabled => 'green',
            self::Disabled => 'gray',
            self::Reloading => 'yellow',
        };
    }
}
