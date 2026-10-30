<?php

namespace App\Enums;

enum ServiceType: string
{
    case Nginx = 'nginx';
    case Caddy = 'caddy';
    case Php = 'php';
    case Mysql = 'mysql';
    case Postgresql = 'postgresql';
    case Redis = 'redis';
    case Supervisor = 'supervisor';

    public function label(): string
    {
        return match ($this) {
            self::Nginx => 'Nginx',
            self::Caddy => 'Caddy',
            self::Php => 'PHP-FPM',
            self::Mysql => 'MySQL',
            self::Postgresql => 'PostgreSQL',
            self::Redis => 'Redis',
            self::Supervisor => 'Supervisor',
        };
    }
}
