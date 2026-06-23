<?php

namespace App\Enums;

enum Provider: string
{
    case DigitalOcean = 'digitalocean';
    case Hetzner = 'hetzner';
    case Vultr = 'vultr';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::DigitalOcean => 'DigitalOcean',
            self::Hetzner => 'Hetzner',
            self::Vultr => 'Vultr',
            self::Custom => 'Custom Server',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::DigitalOcean => 'digitalocean',
            self::Hetzner => 'hetzner',
            self::Vultr => 'vultr',
            self::Custom => 'server',
        };
    }
}
