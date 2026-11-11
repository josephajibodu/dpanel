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
     * A one-line explanation shown when picking the project type.
     */
    public function description(): string
    {
        return match ($this) {
            self::Laravel => 'Runs migrations and caches config on each deploy.',
            self::Symfony => 'Installs Composer packages and warms the cache.',
            self::PhpGeneric => 'Any PHP app. Installs Composer packages.',
            self::WordPress => 'Keeps uploads and wp-config.php between deploys.',
            self::StaticHtml => 'Plain files served as they are, without PHP.',
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
SCRIPT;
    }

    /**
     * Commands run from the new release right after `current` is swapped onto
     * it, so long-running daemons restart into the new code. They can't live
     * in the editable deploy script: that runs before the swap, so a daemon
     * restarted there comes straight back up on the old release — and Horizon
     * always respawns its workers from the release its master booted from,
     * which `queue:restart` alone never changes. Environment edits run them
     * too (SyncEnvironmentJob), from the live release.
     */
    public function postActivationScript(): string
    {
        return match ($this) {
            self::Laravel => <<<'SCRIPT'
$PHP artisan queue:restart
if [ -d vendor/laravel/horizon ]; then $PHP artisan horizon:terminate; fi
if [ -d vendor/laravel/reverb ]; then $PHP artisan reverb:restart; fi
SCRIPT,
            self::Symfony, self::PhpGeneric, self::StaticHtml, self::WordPress => '',
        };
    }

    private function symfonyDeployScript(): string
    {
        return <<<'SCRIPT'
$COMPOSER install --no-dev --no-interaction --prefer-dist --optimize-autoloader

$PHP bin/console cache:clear --env=prod
$PHP bin/console cache:warmup --env=prod
SCRIPT;
    }

    /**
     * Composer only runs when the app has a composer.json: without one it
     * exits with an error, which would fail the deploy of a PHP app that
     * uses no packages.
     */
    private function phpDeployScript(): string
    {
        return <<<'SCRIPT'
if [ -f composer.json ]; then $COMPOSER install --no-dev --no-interaction --prefer-dist --optimize-autoloader; fi
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
