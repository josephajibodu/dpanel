<?php

namespace App\Actions\Certificates;

use App\Models\Certificate;
use App\Services\Ssh\SshConnection;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Writes the wildcard certificate (cert + private key) into a single
 * `(site_id, domain_id)` folder on the server, over an already-open SSH
 * connection. Returns true if anything was written, false on no-op.
 *
 * Does NOT reload nginx — the caller decides when to do that. This action is
 * normally invoked from inside the site provisioner, whose nginx step runs
 * `nginx -t` and reloads as part of its own work.
 */
class SyncWildcardCertificateForDomainAction
{
    public function execute(SshConnection $connection, int $siteId, int $domainId): bool
    {
        $wildcardDomain = '*.'.config('server.free_domain');
        $certificate = Certificate::firstWhere('domain', $wildcardDomain);

        if (! $certificate) {
            return false;
        }

        $certContent = @file_get_contents($certificate->certificate_path);
        $keyContent = @file_get_contents($certificate->private_key_path);

        if ($certContent === false || $keyContent === false) {
            throw new RuntimeException("Wildcard certificate files missing on disk for {$wildcardDomain}");
        }

        $directory = "/etc/nginx/ssl/domains/{$siteId}/{$domainId}";
        $certPath = "{$directory}/server.crt";
        $keyPath = "{$directory}/server.key";

        // The cert is world-readable (0644), so a plain `sha256sum` is enough
        // to decide whether anything changed. Cert + key are paired — if the
        // cert matches we don't bother re-uploading either file.
        $localCertHash = hash('sha256', $certContent);
        if ($this->remoteHash($connection, $certPath) === $localCertHash) {
            return false;
        }

        $connection->sudo("mkdir -p {$directory}");
        $connection->sudo("chmod 700 {$directory}");

        $this->uploadAsRoot($connection, $certContent, $certPath, '0644');
        $this->uploadAsRoot($connection, $keyContent, $keyPath, '0600');

        return true;
    }

    private function remoteHash(SshConnection $connection, string $path): ?string
    {
        try {
            $output = $connection->exec("sha256sum {$path} 2>/dev/null | awk '{print \$1}' || true");
            $hash = trim($output);

            return $hash !== '' ? $hash : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * SFTP-upload to a temp path then `sudo install` into place so the file
     * lands root-owned at the requested mode. `install(1)` does the
     * ownership + mode + atomic rename in one step.
     */
    private function uploadAsRoot(SshConnection $connection, string $content, string $destination, string $mode): void
    {
        $tempPath = '/tmp/wildcard_'.Str::random(16);

        $connection->upload($content, $tempPath);
        $connection->sudo("install -o root -g root -m {$mode} {$tempPath} {$destination}");
        $connection->exec("rm -f {$tempPath}");
    }
}
