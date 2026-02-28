<?php

namespace App\Actions\ServerPhp;

use App\Models\Server;
use App\Services\Ssh\SshService;
use RuntimeException;

class UpdatePhpSettings
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
     * @param  array<string, mixed>  $input  Keys: upload_max_filesize, post_max_size, max_execution_time, memory_limit (nullable)
     */
    public function execute(Server $server, array $input): void
    {
        if (! $server->isReady()) {
            throw new RuntimeException("Server {$server->id} is not ready.");
        }

        $version = $server->getDefaultPhpVersion();
        if ($version === null || $version === '') {
            throw new RuntimeException('Server has no default PHP version set.');
        }

        $connection = $this->sshService->connect($server);

        try {
            $iniPath = "/etc/php/{$version}/fpm/php.ini";
            $content = $connection->sudo("cat {$iniPath}", 10);

            foreach (self::PHP_INI_KEYS as $key) {
                $value = $input[$key] ?? null;
                if ($value === null || $value === '') {
                    continue;
                }

                $valueStr = is_int($value) ? (string) $value : (string) $value;
                $pattern = '/^\s*;?\s*'.preg_quote($key, '/').'\s*=.*$/m';
                $replacement = "{$key} = {$valueStr}";
                $content = preg_replace($pattern, $replacement, $content, 1);
            }

            $tmpPath = '/tmp/php-fpm-php.ini.'.uniqid('', true);
            $connection->upload($content, $tmpPath);
            $connection->sudo("mv {$tmpPath} {$iniPath}", 10);
            $connection->sudo("systemctl restart php{$version}-fpm", 30);
        } finally {
            $connection->disconnect();
        }
    }
}
