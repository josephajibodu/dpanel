<?php

namespace App\Enums;

enum Provider: string
{
    case DigitalOcean = 'digitalocean';
    case Hetzner = 'hetzner';
    case Vultr = 'vultr';
    case Aws = 'aws';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::DigitalOcean => 'DigitalOcean',
            self::Hetzner => 'Hetzner',
            self::Vultr => 'Vultr',
            self::Aws => 'AWS',
            self::Custom => 'Custom Server',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::DigitalOcean => 'digitalocean',
            self::Hetzner => 'hetzner',
            self::Vultr => 'vultr',
            self::Aws => 'aws',
            self::Custom => 'server',
        };
    }

    /**
     * The credential field names this provider expects, in the order the
     * connect form should display them.
     *
     * @return array<int, string>
     */
    public function credentialFields(): array
    {
        return match ($this) {
            self::Aws => ['access_key_id', 'secret_access_key'],
            default => ['api_token'],
        };
    }
}
