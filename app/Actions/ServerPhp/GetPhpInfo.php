<?php

namespace App\Actions\ServerPhp;

use App\Models\Server;
use App\Services\Ssh\SshConnection;
use App\Services\Ssh\SshService;
use Illuminate\Support\Facades\Cache;

class GetPhpInfo
{
    private const PHP_INI_KEYS = [
        'upload_max_filesize',
        'post_max_size',
        'max_execution_time',
        'memory_limit',
    ];

    private const CACHE_TTL = 300; // 5 minutes

    public function __construct(
        private SshService $sshService
    ) {}

    public static function cacheKey(int $serverId, string $version): string
    {
        return "php_settings:{$serverId}:{$version}";
    }

    public static function invalidateCache(int $serverId, string $version): void
    {
        Cache::forget(self::cacheKey($serverId, $version));
    }

    /**
     * Return cached php.ini settings for the server's default PHP version.
     *
     * @return array<string, string>
     */
    public function execute(Server $server): array
    {
        $version = $server->getDefaultPhpVersion();

        if ($version === null || $version === '' || ! $server->isReady()) {
            return [];
        }

        return Cache::remember(
            self::cacheKey($server->id, $version),
            self::CACHE_TTL,
            fn () => $this->fetchViaSsh($server, $version),
        );
    }

    /**
     * @return array<string, string>
     */
    private function fetchViaSsh(Server $server, string $version): array
    {
        try {
            $connection = $this->sshService->connect($server);
        } catch (\Throwable) {
            return [];
        }

        try {
            return $this->getSettingsForVersion($connection, $version);
        } finally {
            $connection->disconnect();
        }
    }

    /**
     * @return array<string, string>
     */
    private function getSettingsForVersion(SshConnection $connection, string $version): array
    {
        try {
            $keys = implode('|', array_map('preg_quote', self::PHP_INI_KEYS));
            $iniPath = "/etc/php/{$version}/fpm/php.ini";
            $output = $connection->sudo("grep -E '^({$keys})\\s*=' {$iniPath} 2>/dev/null || true", 10);
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
