<?php

namespace App\Actions\ServerPhp;

use App\Enums\PhpExtension;
use App\Models\Server;
use App\Services\Ssh\SshService;
use RuntimeException;

class ChangePhpExtension
{
    public function __construct(
        private SshService $sshService
    ) {}

    /**
     * Install or remove an optional extension for one PHP version, reload
     * PHP-FPM so sites pick it up, and report which optional extensions are
     * installed for that version afterwards.
     *
     * @return list<string> Installed optional extension values.
     */
    public function execute(Server $server, string $phpVersion, PhpExtension $extension, bool $install): array
    {
        if (! $server->isReady()) {
            throw new RuntimeException("Server {$server->id} is not ready.");
        }

        $connection = $this->sshService->connect($server);

        try {
            $package = $extension->package($phpVersion);

            if ($install) {
                $connection->sudo('apt-get update', 120);
                $connection->sudo("DEBIAN_FRONTEND=noninteractive apt-get install -y {$package}", 600);
            } else {
                $connection->sudo("DEBIAN_FRONTEND=noninteractive apt-get remove -y {$package}", 300);
            }

            $connection->sudo("systemctl reload php{$phpVersion}-fpm", 30);

            $output = $connection->exec("dpkg-query -W -f='\${Package} \${db:Status-Status}\\n' 'php{$phpVersion}-*' 2>/dev/null || true");
        } finally {
            $connection->disconnect();
        }

        return $this->installedExtensions($output, $phpVersion);
    }

    /**
     * @return list<string>
     */
    private function installedExtensions(string $dpkgOutput, string $phpVersion): array
    {
        $installedPackages = collect(preg_split('/\R/', trim($dpkgOutput)))
            ->map(fn (string $line): array => preg_split('/\s+/', trim($line)))
            ->filter(fn (array $parts): bool => ($parts[1] ?? null) === 'installed')
            ->map(fn (array $parts): string => $parts[0]);

        return collect(PhpExtension::cases())
            ->filter(fn (PhpExtension $extension): bool => $installedPackages->contains($extension->package($phpVersion)))
            ->map(fn (PhpExtension $extension): string => $extension->value)
            ->values()
            ->all();
    }
}
