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
        $sharedPath = $this->site->sharedPath();

        // A fresh release won't have .env.example yet at this point (real code
        // hasn't been cloned — see createBootstrapRelease()), so seed an empty
        // file here; configureEnvValues() below fills in the real keys.
        $this->runAsOwner("touch {$sharedPath}/.env");

        $this->configureEnvValues();
        $this->createStorageSkeleton();

        $this->runAsOwner("{$this->sudo}chown {$this->owner}:{$this->group} {$sharedPath}/.env && {$this->sudo}chmod {$this->secretFileMode} {$sharedPath}/.env");
    }

    protected function configureEnvValues(): void
    {
        $envPath = "{$this->site->sharedPath()}/.env";
        $domain = $this->site->domain;

        $this->sedEnv($envPath, 'APP_ENV', 'production');
        $this->sedEnv($envPath, 'APP_DEBUG', 'false');
        $this->sedEnv($envPath, 'APP_URL', "https://{$domain}");
        $this->sedEnv($envPath, 'APP_KEY', $this->generateAppKey());

        $serverDatabase = $this->site->serverDatabase;
        if (! $serverDatabase) {
            $sqlitePath = $this->ensureSqliteDatabaseFile();

            $this->sedEnv($envPath, 'DB_CONNECTION', 'sqlite');
            $this->sedEnv($envPath, 'DB_DATABASE', $sqlitePath);

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
     * Sites without a server database fall back to SQLite; create the file so
     * the app can boot (sessions, queues) before the first deploy runs
     * migrations. Lives in shared/ since the database must persist across
     * releases. Returns the absolute path of the database file.
     */
    private function ensureSqliteDatabaseFile(): string
    {
        $sqlitePath = $this->site->sqliteDatabasePath();

        $this->runAsOwner('mkdir -p '.dirname($sqlitePath)." && touch {$sqlitePath}");

        return $sqlitePath;
    }

    /**
     * Create the writable storage skeleton in shared/ up front, independent
     * of whether real application code exists yet — every release symlinks
     * storage/ into this (see ProjectType::sharedSymlinks()), so sessions,
     * cached views, and logs survive across deploys and rollbacks.
     */
    private function createStorageSkeleton(): void
    {
        $storagePath = "{$this->site->sharedPath()}/storage";

        $this->runAsOwner(
            "mkdir -p {$storagePath}/app/public ".
            "{$storagePath}/framework/cache/data {$storagePath}/framework/sessions {$storagePath}/framework/views ".
            "{$storagePath}/logs"
        );
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

        $this->runAsOwner(
            "if [ -f {$envPath} ] && grep -q '^{$key}=' {$envPath}; then "
            ."sed -i 's#^{$key}=.*#{$key}={$escapedValue}#' {$envPath}; "
            ."elif [ -f {$envPath} ]; then "
            ."echo '{$key}={$value}' >> {$envPath}; fi"
        );
    }

    protected function setPermissions(): void
    {
        parent::setPermissions();

        $sharedPath = $this->site->sharedPath();

        // bootstrap/cache doesn't exist yet (no real code cloned during
        // provisioning) — the deploy strategy chmods it per-release instead.
        $this->runAsOwner("{$this->sudo}chown -R {$this->owner}:{$this->group} {$sharedPath}/storage {$sharedPath}/database 2>/dev/null || true");
        $this->runAsOwner("{$this->sudo}chmod -R 775 {$sharedPath}/storage 2>/dev/null || true");
        $this->runAsOwner("if [ -d {$sharedPath}/database ]; then {$this->sudo}chmod -R 775 {$sharedPath}/database; fi");
        $this->runAsOwner("if [ -f {$sharedPath}/.env ]; then {$this->sudo}chmod {$this->secretFileMode} {$sharedPath}/.env; fi");
    }
}
