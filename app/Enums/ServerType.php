<?php

namespace App\Enums;

enum ServerType: string
{
    case App = 'app';
    case Web = 'web';
    case Worker = 'worker';
    case Database = 'database';
    case Cache = 'cache';
    case Loadbalancer = 'loadbalancer';

    public function label(): string
    {
        return match ($this) {
            self::App => 'App Server',
            self::Web => 'Web Server',
            self::Worker => 'Worker Server',
            self::Database => 'Database Server',
            self::Cache => 'Cache Server',
            self::Loadbalancer => 'Load Balancer',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::App => 'Full stack server with PHP, Nginx, database, and Redis.',
            self::Web => 'Web server with PHP and Nginx, connects to external database.',
            self::Worker => 'Queue worker server for background job processing.',
            self::Database => 'Dedicated database server (MySQL, PostgreSQL, or MariaDB).',
            self::Cache => 'Dedicated Redis server for caching and queues.',
            self::Loadbalancer => 'Load balancer for distributing traffic across servers.',
        };
    }

    /**
     * The services this type ticks by default when creating a server. A type
     * is only a preset: the user can change the selection before creating it.
     *
     * @return array{php: bool, web_server: bool, database: bool, redis: bool}
     */
    public function defaultServices(): array
    {
        return match ($this) {
            self::App => ['php' => true, 'web_server' => true, 'database' => true, 'redis' => true],
            self::Web => ['php' => true, 'web_server' => true, 'database' => false, 'redis' => false],
            self::Worker => ['php' => true, 'web_server' => false, 'database' => false, 'redis' => true],
            self::Database => ['php' => false, 'web_server' => false, 'database' => true, 'redis' => false],
            self::Cache => ['php' => false, 'web_server' => false, 'database' => false, 'redis' => true],
            self::Loadbalancer => ['php' => false, 'web_server' => true, 'database' => false, 'redis' => false],
        };
    }

    /**
     * Whether users can pick this type when creating a server. Load balancers
     * stay hidden until load balancing itself is built.
     */
    public function isSelectable(): bool
    {
        return $this !== self::Loadbalancer;
    }

    /**
     * @return array<int, self>
     */
    public static function selectable(): array
    {
        return array_values(array_filter(self::cases(), fn (self $type): bool => $type->isSelectable()));
    }
}
