<?php

namespace App\Enums;

enum SiteStatus: string
{
    case Pending = 'pending';
    case Installing = 'installing';
    case Deployed = 'deployed';
    case Deploying = 'deploying';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Installing => 'Installing',
            self::Deployed => 'Deployed',
            self::Deploying => 'Deploying',
            self::Failed => 'Failed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Installing => 'blue',
            self::Deployed => 'green',
            self::Deploying => 'yellow',
            self::Failed => 'red',
        };
    }
}
