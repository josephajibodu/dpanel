<?php

namespace App\Enums;

/**
 * Where a web server config file belongs. The same for Nginx and Caddy.
 */
enum NginxFileSection: string
{
    case Main = 'main';
    case Server = 'server';
    case After = 'after';

    public function label(): string
    {
        return match ($this) {
            self::Main => 'Main configuration',
            self::Server => 'Inside the site block',
            self::After => 'Outside the site block',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Main => 'Written by flitops from the site\'s settings. Read-only.',
            self::Server => 'Rules for this site: headers, redirects, upload limits, proxied paths.',
            self::After => 'Advanced: definitions that sit beside the site, or extra site blocks.',
        };
    }

    public function isReadOnly(): bool
    {
        return $this === self::Main;
    }
}
