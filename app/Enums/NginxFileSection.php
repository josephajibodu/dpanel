<?php

namespace App\Enums;

enum NginxFileSection: string
{
    case Main = 'main';
    case Before = 'before';
    case Server = 'server';
    case Locations = 'locations';
    case After = 'after';

    public function label(): string
    {
        return match ($this) {
            self::Main => 'Main Configuration',
            self::Before => 'Before',
            self::Server => 'Server',
            self::Locations => 'Locations',
            self::After => 'After',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Main => 'Auto-generated per-domain nginx site configuration',
            self::Before => 'Loaded before the server block',
            self::Server => 'Injected inside the server block',
            self::Locations => 'Injected as location directives',
            self::After => 'Loaded after the server block',
        };
    }

    public function isReadOnly(): bool
    {
        return $this === self::Main;
    }
}
