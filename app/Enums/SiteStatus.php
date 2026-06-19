<?php

namespace App\Enums;

enum SiteStatus: string
{
    case Pending = 'pending';
    case Installing = 'installing';
    case Provisioned = 'provisioned';
    case Deployed = 'deployed';
    case Deploying = 'deploying';
    case Failed = 'failed';
    case Deleting = 'deleting';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Installing => 'Installing',
            self::Provisioned => 'Provisioned',
            self::Deployed => 'Deployed',
            self::Deploying => 'Deploying',
            self::Failed => 'Failed',
            self::Deleting => 'Deleting',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Installing => 'blue',
            self::Provisioned => 'teal',
            self::Deployed => 'green',
            self::Deploying => 'yellow',
            self::Failed => 'red',
            self::Deleting => 'orange',
        };
    }
}
