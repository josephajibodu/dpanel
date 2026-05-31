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
        $this->sedEnv($envPath, 'APP_KEY', $this->generateAppKey());

        $serverDatabase = $this->site->serverDatabase;
        if (! $serverDatabase) {
            $this->ensureSqliteDatabaseFile();

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
     * Laravel's default .env.example uses SQLite; create the file so the app
     * can boot (sessions, queues) before the first deploy runs migrations.
     */
    private function ensureSqliteDatabaseFile(): void
    {
        $sqlitePath = "{$this->siteRoot}/database/database.sqlite";

        $this->connection->exec("mkdir -p {$this->siteRoot}/database && touch {$sqlitePath}");
    }

    /**
     * Generate a Laravel-compatible APP_KEY without needing artisan/vendor.
     * Mirrors Illuminate\Foundation\Console\KeyGenerateCommand::generateRandomKey()
     * for AES-256-CBC (32 random bytes, base64-encoded, prefixed with "base64:").
     * We do this in PHP because at provisioning time vendor/ does not yet exist,
     * so `php artisan key:generate` cannot bootstrap.
     */
    private function generateAppKey(): string
    {
        return 'base64:'.base64_encode(random_bytes(32));
    }

    /**
     * Replace or append an environment variable in a .env file via sed.
     */
    private function sedEnv(string $envPath, string $key, string $value): void
    {
        $escapedValue = str_replace(['\\', '#', '&'], ['\\\\', '\\#', '\\&'], $value);

        $this->connection->exec(
            "if [ -f {$envPath} ] && grep -q '^{$key}=' {$envPath}; then "
            ."sed -i 's#^{$key}=.*#{$key}={$escapedValue}#' {$envPath}; "
            ."elif [ -f {$envPath} ]; then "
            ."echo '{$key}={$value}' >> {$envPath}; fi"
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
