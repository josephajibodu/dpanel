<?php

namespace App\Services\WebServer;

use App\Models\Site;
use App\Services\Ssh\SshConnection;
use App\Services\Ssh\SshService;

/**
 * Reads the end of a site's own web server error log over SSH.
 */
class SiteErrorLogReader
{
    public function __construct(
        private SshService $sshService,
    ) {}

    /**
     * Whether the site's web server keeps an error log for it.
     */
    public function isAvailable(Site $site): bool
    {
        return $site->server->webServer()->errorLogPath($site->ulid) !== null;
    }

    /**
     * The last lines of the site's error log, oldest first. Empty when the
     * web server keeps no log per site, or nothing has been logged yet.
     * Throws when the server can't be reached.
     *
     * @param  SshConnection|null  $connection  When null, a connection is opened and closed. When provided, it is not closed.
     * @return array<int, string>
     */
    public function recentLines(Site $site, int $lines, ?SshConnection $connection = null): array
    {
        $path = $site->server->webServer()->errorLogPath($site->ulid);

        if ($path === null) {
            return [];
        }

        $shouldDisconnect = $connection === null;
        $connection ??= $this->sshService->connect($site->server);

        try {
            // The log belongs to root, so it's read with sudo. A site with no
            // errors yet (or not resynced since per-site logs were added) has
            // no file, which isn't a failure.
            $escapedPath = escapeshellarg($path);
            $output = $connection->exec("if sudo test -f {$escapedPath}; then sudo tail -n {$lines} {$escapedPath}; fi", 15);
        } finally {
            if ($shouldDisconnect) {
                $connection->disconnect();
            }
        }

        return array_values(array_filter(
            explode("\n", rtrim($output, "\n")),
            fn (string $line): bool => $line !== '',
        ));
    }
}
