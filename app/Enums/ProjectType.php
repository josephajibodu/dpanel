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
cd $SITE_ROOT

git pull origin $BRANCH

$COMPOSER install --no-dev --no-interaction --prefer-dist --optimize-autoloader

( flock -w 10 9 || exit 1
    echo 'Restarting FPM...'; sudo -S service $PHP_FPM reload ) 9>/tmp/fpmlock

if [ -f artisan ]; then
    $PHP artisan migrate --force
    $PHP artisan config:cache
    $PHP artisan route:cache
    $PHP artisan view:cache
    $PHP artisan event:cache
fi
SCRIPT;
    }

    private function symfonyDeployScript(): string
    {
        return <<<'SCRIPT'
cd $SITE_ROOT

git pull origin $BRANCH

$COMPOSER install --no-dev --no-interaction --prefer-dist --optimize-autoloader

$PHP bin/console cache:clear --env=prod
$PHP bin/console cache:warmup --env=prod

( flock -w 10 9 || exit 1
    echo 'Restarting FPM...'; sudo -S service $PHP_FPM reload ) 9>/tmp/fpmlock
SCRIPT;
    }

    private function phpDeployScript(): string
    {
        return <<<'SCRIPT'
cd $SITE_ROOT

git pull origin $BRANCH

$COMPOSER install --no-dev --no-interaction --prefer-dist --optimize-autoloader

( flock -w 10 9 || exit 1
    echo 'Restarting FPM...'; sudo -S service $PHP_FPM reload ) 9>/tmp/fpmlock
SCRIPT;
    }

    private function htmlDeployScript(): string
    {
        return <<<'SCRIPT'
cd $SITE_ROOT

git pull origin $BRANCH
SCRIPT;
    }

    private function wordpressDeployScript(): string
    {
        return <<<'SCRIPT'
cd $SITE_ROOT

git pull origin $BRANCH

( flock -w 10 9 || exit 1
    echo 'Restarting FPM...'; sudo -S service $PHP_FPM reload ) 9>/tmp/fpmlock
SCRIPT;
    }
}
