<?php

namespace App\Services\SiteProvisioning;

use App\Enums\SiteProvisioningStep;
use App\Models\DatabaseUser;

class LaravelSiteProvisioner extends BaseSiteProvisioner
{
    public function steps(): array
    {
        return SiteProvisioningStep::enumCasesForProjectType($this->site->project_type);
    }

    protected function createEnvironmentFile(): void
    {
        $this->connection->exec("if [ -f {$this->siteRoot}/.env.example ]; then cp {$this->siteRoot}/.env.example {$this->siteRoot}/.env; fi");

        $this->configureEnvValues();

        $this->connection->exec("if [ -f {$this->siteRoot}/.env ]; then sudo chown {$this->serverUser}:{$this->webUser} {$this->siteRoot}/.env && sudo chmod 640 {$this->siteRoot}/.env; fi");
    }

    protected function configureEnvValues(): void
    {
        $envPath = "{$this->siteRoot}/.env";
        $domain = $this->site->domain;

        $this->sedEnv($envPath, 'APP_ENV', 'production');
        $this->sedEnv($envPath, 'APP_DEBUG', 'false');
        $this->sedEnv($envPath, 'APP_URL', "https://{$domain}");

        $serverDatabase = $this->site->serverDatabase;
        if (! $serverDatabase) {
            return;
        }

        $server = $this->site->server;
        $dbUser = DatabaseUser::query()
            ->where('server_id', $server->id)
            ->whereJsonContains('databases', $serverDatabase->name)
            ->first();

        if (! $dbUser) {
            return;
        }

        $dbType = $server->database_type;
        $connection = match ($dbType) {
            'postgresql' => 'pgsql',
            default => 'mysql',
        };
        $port = match ($dbType) {
            'postgresql' => '5432',
            default => '3306',
        };

        $this->sedEnv($envPath, 'DB_CONNECTION', $connection);
        $this->sedEnv($envPath, 'DB_HOST', '127.0.0.1');
        $this->sedEnv($envPath, 'DB_PORT', $port);
        $this->sedEnv($envPath, 'DB_DATABASE', $serverDatabase->name);
        $this->sedEnv($envPath, 'DB_USERNAME', $dbUser->username);
        $this->sedEnv($envPath, 'DB_PASSWORD', $dbUser->password);
    }

    /**
     * Replace or append an environment variable in a .env file via sed.
     */
    private function sedEnv(string $envPath, string $key, string $value): void
    {
        $escapedValue = str_replace(['/', '&', '\\'], ['\\/', '\\&', '\\\\'], $value);

        $this->connection->exec(
            "if [ -f {$envPath} ] && grep -q '^{$key}=' {$envPath}; then "
            ."sed -i 's/^{$key}=.*/{$key}={$escapedValue}/' {$envPath}; "
            ."elif [ -f {$envPath} ]; then "
            ."echo '{$key}={$value}' >> {$envPath}; fi"
        );
    }

    protected function installDependencies(): void
    {
        $this->connection->exec(
            "if [ -f {$this->siteRoot}/composer.json ]; then cd {$this->siteRoot} && composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader; fi",
            timeout: 300,
        );

        $this->connection->exec("if [ -f {$this->siteRoot}/artisan ] && [ -f {$this->siteRoot}/.env ]; then cd {$this->siteRoot} && {$this->phpBinary()} artisan key:generate --force; fi");
    }

    protected function buildAssets(): void
    {
        $packageManager = $this->site->package_manager ?? 'npm';
        $buildCommand = $this->site->build_command ?? 'npm run build';

        if ($packageManager === 'none') {
            return;
        }

        $installCommand = match ($packageManager) {
            'pnpm' => 'if [ -f pnpm-lock.yaml ]; then pnpm install --frozen-lockfile; else pnpm install; fi',
            'yarn' => 'if [ -f yarn.lock ]; then yarn install --frozen-lockfile; else yarn install; fi',
            'bun' => 'if [ -f bun.lockb ]; then bun install --frozen-lockfile; else bun install; fi',
            default => 'if [ -f package-lock.json ]; then npm ci; else npm install; fi',
        };

        $this->connection->exec(
            "if [ -f {$this->siteRoot}/package.json ]; then cd {$this->siteRoot} && {$installCommand} && {$buildCommand}; fi",
            timeout: 600,
        );
    }

    protected function runMigrations(): void
    {
        $this->connection->exec(
            "if [ -f {$this->siteRoot}/artisan ]; then cd {$this->siteRoot} && {$this->phpBinary()} artisan migrate --force; fi",
            timeout: 120,
        );
    }

    protected function setPermissions(): void
    {
        parent::setPermissions();

        $this->connection->exec("sudo chmod -R 775 {$this->siteRoot}/storage {$this->siteRoot}/bootstrap/cache 2>/dev/null || true");

        $this->connection->exec("if [ -d {$this->siteRoot}/database ]; then sudo chmod -R 775 {$this->siteRoot}/database; fi");

        $this->connection->exec("if [ -f {$this->siteRoot}/.env ]; then sudo chmod 640 {$this->siteRoot}/.env; fi");
    }
}
