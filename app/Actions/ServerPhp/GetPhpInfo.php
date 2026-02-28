<?php

namespace App\Actions\ServerPhp;

use App\Models\Server;
use App\Services\Ssh\SshConnection;
use App\Services\Ssh\SshService;

class GetPhpInfo
{
    private const PHP_INI_KEYS = [
        'upload_max_filesize',
        'post_max_size',
        'max_execution_time',
        'memory_limit',
    ];

    public function __construct(
        private SshService $sshService
    ) {}

    /**
     * Get installed PHP versions, default version's settings from the server.
     *
     * @return array{installed_versions: array<int, string>, settings: array<string, string>}
     */
    public function execute(Server $server): array
    {
        if (! $server->isReady()) {
            return [
                'installed_versions' => [],
                'settings' => [],
            ];
        }

        try {
            $connection = $this->sshService->connect($server);
        } catch (\Throwable) {
            return [
                'installed_versions' => [],
                'settings' => [],
            ];
        }

        try {
            $defaultPhp = $server->php();
            $defaultVersion = $defaultPhp?->installed_version ?? $defaultPhp?->version ?? $server->php_version;

            $phpServices = $server->installedServices()->where('type', 'php')->get();
            $installedVersionsFromDb = $phpServices->map(fn ($s) => $s->installed_version ?? $s->version)->filter()->unique()->values()->all();
            $installedVersions = $this->getInstalledVersions($connection);
            if ($installedVersionsFromDb !== []) {
                $installedVersions = array_values(array_unique(array_merge($installedVersionsFromDb, $installedVersions)));
                sort($installedVersions);
            }

            $settings = [];
            if ($defaultVersion !== null && $defaultVersion !== '' && in_array($defaultVersion, $installedVersions, true)) {
                $settings = $this->getSettingsForVersion($connection, $defaultVersion);
            }

            return [
                'installed_versions' => $installedVersions,
                'settings' => $settings,
            ];
        } finally {
            $connection->disconnect();
        }
    }

    /**
     * @return array<int, string>
     */
    private function getInstalledVersions(SshConnection $connection): array
    {
        try {
            $output = $connection->sudo('ls -1 /etc/php 2>/dev/null || true', 10);
        } catch (\Throwable) {
            return [];
        }

        $lines = array_filter(array_map('trim', explode("\n", $output)));
        $versions = [];

        foreach ($lines as $line) {
            if (preg_match('/^\d+\.\d+$/', $line)) {
                $versions[] = $line;
            }
        }

        sort($versions);

        return $versions;
    }

    /**
     * @return array<string, string>
     */
    private function getSettingsForVersion(SshConnection $connection, string $version): array
    {
        try {
            $iniPath = "/etc/php/{$version}/fpm/php.ini";
            $output = $connection->sudo("cat {$iniPath} 2>/dev/null || true", 10);
        } catch (\Throwable) {
            return [];
        }

        $settings = [];

        foreach (self::PHP_INI_KEYS as $key) {
            if (preg_match('/^\s*'.preg_quote($key, '/').'\s*=\s*(.+)$/m', $output, $m)) {
                $settings[$key] = trim($m[1]);
            }
        }

        return $settings;
    }
}
