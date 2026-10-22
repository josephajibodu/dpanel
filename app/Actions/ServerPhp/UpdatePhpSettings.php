<?php

namespace App\Actions\ServerPhp;

use App\Models\Server;
use App\Services\Ssh\SshService;
use InvalidArgumentException;
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
     *
     * @throws InvalidArgumentException when a value contains a line break or other control character
     */
    public function execute(Server $server, array $input): void
    {
        $settings = $this->settingsToWrite($input);

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

            foreach ($settings as $key => $value) {
                $pattern = '/^\s*;?\s*'.preg_quote($key, '/').'\s*=.*$/m';
                $content = preg_replace_callback($pattern, fn (): string => "{$key} = {$value}", $content, 1);
            }

            $tmpPath = '/tmp/php-fpm-php.ini.'.uniqid('', true);
            $connection->upload($content, $tmpPath);
            $connection->sudo("mv {$tmpPath} {$iniPath}", 10);
            $connection->sudo("systemctl restart php{$version}-fpm", 30);
        } finally {
            $connection->disconnect();
        }
    }

    /**
     * Pick out the php.ini settings to write, refusing any value that could
     * add extra lines to php.ini.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    private function settingsToWrite(array $input): array
    {
        $settings = [];

        foreach (self::PHP_INI_KEYS as $key) {
            $value = $input[$key] ?? null;
            if ($value === null || $value === '') {
                continue;
            }

            $value = (string) $value;
            if (preg_match('/[\x00-\x1F\x7F]/', $value)) {
                throw new InvalidArgumentException("PHP setting {$key} contains a line break or other control character.");
            }

            $settings[$key] = $value;
        }

        return $settings;
    }
}
