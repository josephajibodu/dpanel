<?php

namespace App\Enums;

/**
 * The program that keeps a Laravel site loaded in memory when the site runs
 * on Laravel Octane.
 */
enum OctaneServer: string
{
    case FrankenPhp = 'frankenphp';

    public function label(): string
    {
        return match ($this) {
            self::FrankenPhp => 'FrankenPHP',
        };
    }
}
