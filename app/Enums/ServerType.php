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
            self::Cache => 'Dedicated cache server (Redis or Memcached).',
            self::Loadbalancer => 'Load balancer for distributing traffic across servers.',
        };
    }

    /**
     * Get the services that should be installed for this server type.
     *
     * @return array<string, bool>
     */
    public function defaultServices(): array
    {
        return match ($this) {
            self::App => [
                'php' => true,
                'nginx' => true,
                'database' => true,
                'redis' => true,
                'supervisor' => true,
            ],
            self::Web => [
                'php' => true,
                'nginx' => true,
                'database' => false,
                'redis' => false,
                'supervisor' => true,
            ],
            self::Worker => [
                'php' => true,
                'nginx' => false,
                'database' => false,
                'redis' => true,
                'supervisor' => true,
            ],
            self::Database => [
                'php' => false,
                'nginx' => false,
                'database' => true,
                'redis' => false,
                'supervisor' => false,
            ],
            self::Cache => [
                'php' => false,
                'nginx' => false,
                'database' => false,
                'redis' => true,
                'supervisor' => false,
            ],
            self::Loadbalancer => [
                'php' => false,
                'nginx' => true,
                'database' => false,
                'redis' => false,
                'supervisor' => false,
            ],
        };
    }

    /**
     * Check if this server type supports sites.
     */
    public function supportsSites(): bool
    {
        return match ($this) {
            self::App, self::Web, self::Loadbalancer => true,
            self::Worker, self::Database, self::Cache => false,
        };
    }

    /**
     * Check if this server type requires PHP.
     */
    public function requiresPhp(): bool
    {
        return match ($this) {
            self::App, self::Web, self::Worker => true,
            self::Database, self::Cache, self::Loadbalancer => false,
        };
    }
}
