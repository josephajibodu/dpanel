<?php

namespace App\Enums;

enum Provider: string
{
    case DigitalOcean = 'digitalocean';
    case Hetzner = 'hetzner';
    case Vultr = 'vultr';

    public function label(): string
    {
        return match ($this) {
            self::DigitalOcean => 'DigitalOcean',
            self::Hetzner => 'Hetzner',
            self::Vultr => 'Vultr',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::DigitalOcean => 'digitalocean',
            self::Hetzner => 'hetzner',
            self::Vultr => 'vultr',
        };
    }
}
