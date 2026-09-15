<?php

namespace App\Enums;

enum ProjectType: string
{
    case Laravel = 'laravel';
    case PhpGeneric = 'php';
    case StaticHtml = 'html';
    case Symfony = 'symfony';
    case WordPress = 'wordpress';

    public function label(): string
    {
        return match ($this) {
            self::Laravel => 'Laravel',
            self::PhpGeneric => 'PHP',
            self::StaticHtml => 'Static HTML',
            self::Symfony => 'Symfony',
            self::WordPress => 'WordPress',
        };
    }

    /**
     * Get the default public directory for this project type.
     */
    public function defaultDirectory(): string
    {
        return match ($this) {
            self::Laravel, self::Symfony => '/public',
            self::PhpGeneric, self::StaticHtml, self::WordPress => '/',
        };
    }

    /**
     * Get the default deploy script for this project type.
     */
    public function defaultDeployScript(): string
    {
        return match ($this) {
            self::Laravel => $this->laravelDeployScript(),
            self::Symfony => $this->symfonyDeployScript(),
            self::PhpGeneric => $this->phpDeployScript(),
            self::StaticHtml => $this->htmlDeployScript(),
            self::WordPress => $this->wordpressDeployScript(),
        };
    }

    private function laravelDeployScript(): string
    {
        return <<<'SCRIPT'
$COMPOSER install --no-dev --no-interaction --prefer-dist --optimize-autoloader

if [ -f package-lock.json ]; then npm ci; else npm install; fi
npm run build

$PHP artisan migrate --force
$PHP artisan config:cache
$PHP artisan route:cache
$PHP artisan view:cache
$PHP artisan event:cache
$PHP artisan queue:restart
SCRIPT;
    }

    private function symfonyDeployScript(): string
    {
        return <<<'SCRIPT'
$COMPOSER install --no-dev --no-interaction --prefer-dist --optimize-autoloader

$PHP bin/console cache:clear --env=prod
$PHP bin/console cache:warmup --env=prod
SCRIPT;
    }

    private function phpDeployScript(): string
    {
        return <<<'SCRIPT'
$COMPOSER install --no-dev --no-interaction --prefer-dist --optimize-autoloader
SCRIPT;
    }

    private function htmlDeployScript(): string
    {
        return '';
    }

    private function wordpressDeployScript(): string
    {
        return '';
    }

    /**
     * Files/directories that must persist across releases, mapped from their
     * path inside a fresh release to their path inside the site's shared/
     * directory (both relative to their respective roots). The zero-downtime
     * deploy strategy resolves both to absolute paths and symlinks each of
     * these right after cloning a new release.
     *
     * @return array<string, string>
     */
    public function sharedSymlinks(): array
    {
        return match ($this) {
            self::Laravel => [
                '.env' => '.env',
                'storage' => 'storage',
                'database/database.sqlite' => 'database/database.sqlite',
            ],
            self::Symfony => [
                '.env' => '.env',
                '.env.local' => '.env.local',
                'var/cache' => 'var/cache',
                'var/log' => 'var/log',
            ],
            self::PhpGeneric => [
                '.env' => '.env',
            ],
            self::WordPress => [
                'wp-config.php' => 'wp-config.php',
                'wp-content/uploads' => 'wp-content/uploads',
            ],
            self::StaticHtml => [],
        };
    }
}
