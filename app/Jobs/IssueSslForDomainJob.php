<?php

namespace App\Jobs;

use App\Enums\WwwRedirect;
use App\Events\SiteDomainsUpdated;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Notifications\SslCertificateRequestFailed;
use App\Services\Ssh\SshService;
use DateTimeImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class IssueSslForDomainJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 420;

    public int $tries = 3;

    public function __construct(
        public SiteDomain $siteDomain,
        public Site $site,
    ) {}

    public function handle(SshService $sshService): void
    {
        $this->siteDomain->loadMissing('site');
        $this->site->loadMissing('server');

        $server = $this->site->server;

        if ($server->webServer()->obtainsCertificates()) {
            $this->switchToHttps();

            return;
        }

        $hostname = $this->siteDomain->hostname;
        $email = $server->user?->email ?? config('mail.from.address');

        $connection = $sshService->connect($server);

        try {
            // Install certbot if it wasn't present when the server was provisioned.
            $hasCertbot = trim($connection->exec('which certbot 2>/dev/null || true')) !== '';
            if (! $hasCertbot) {
                $connection->exec('sudo DEBIAN_FRONTEND=noninteractive apt-get install -y certbot', 300);
            }

            $certDir = $server->webServer()->certificateDirectory($this->site->id, $this->siteDomain->id);

            $webRoot = $this->site->acmeChallengeRoot();
            $connection->exec('sudo mkdir -p '.escapeshellarg($webRoot).'/.well-known/acme-challenge');

            if ($this->site->isIsolated()) {
                $connection->exec('sudo chmod 755 '.escapeshellarg($webRoot).' '.escapeshellarg($webRoot.'/.well-known').' '.escapeshellarg($webRoot.'/.well-known/acme-challenge'));
            } else {
                // The web root lives inside the current release, so a root-owned folder here would stop that release from being pruned later.
                $connection->exec('sudo chown -R '.escapeshellarg($this->site->systemUser().':').' '.escapeshellarg($webRoot.'/.well-known'));
            }
            $connection->exec("sudo mkdir -p {$certDir}");

            // For apex domains with a www redirect, include www in the cert so that
            // both the primary hostname and the www redirect server block work over HTTPS.
            $domainFlags = '-d '.escapeshellarg($hostname);
            if ($this->siteDomain->effectiveWwwRedirect() !== WwwRedirect::None) {
                $domainFlags .= ' -d '.escapeshellarg($this->siteDomain->wwwHostname());
            }

            $result = $connection->exec(
                'sudo certbot certonly --webroot'
                .' --webroot-path='.escapeshellarg($webRoot)
                ." {$domainFlags}"
                .' --non-interactive'
                .' --agree-tos'
                .' --email '.escapeshellarg($email)
                .' --no-eff-email 2>&1'
            );

            if (! str_contains($result, 'Congratulations') && ! str_contains($result, 'Certificate not yet due for renewal')) {
                throw new \RuntimeException("Certbot failed for {$hostname}: {$result}");
            }

            $letsencryptBase = "/etc/letsencrypt/live/{$hostname}";

            $connection->exec("sudo cp {$letsencryptBase}/fullchain.pem {$certDir}/server.crt");
            $connection->exec("sudo cp {$letsencryptBase}/privkey.pem {$certDir}/server.key");
            $connection->exec("sudo chmod 644 {$certDir}/server.crt");
            $connection->exec("sudo chmod 600 {$certDir}/server.key");

            $deployHookPath = self::deployHookPath($this->siteDomain->id);
            $escapedHook = str_replace("'", "'\\''", $this->deployHookScript($letsencryptBase, $certDir));
            $connection->exec('sudo mkdir -p '.dirname($deployHookPath));
            $connection->exec("echo '{$escapedHook}' | sudo tee {$deployHookPath} > /dev/null");
            $connection->exec("sudo chmod 755 {$deployHookPath}");

            $expiresAt = $this->readExpiry($connection->exec("sudo cat {$certDir}/server.crt"), $hostname);

            $this->siteDomain->update([
                'ssl_enabled_at' => now(),
                ...($expiresAt !== null ? ['ssl_expires_at' => $expiresAt] : []),
            ]);
            broadcast(new SiteDomainsUpdated($this->site));

            SyncSiteNginxJob::dispatch($this->site);
        } finally {
            $connection->disconnect();
        }
    }

    /**
     * For a web server that gets its own certificates (Caddy), turning on
     * HTTPS only means serving the domain over HTTPS: Caddy then obtains the
     * certificate and renews it. The expiry date is filled in by the daily
     * certificate check, which reads the certificate the server serves.
     */
    private function switchToHttps(): void
    {
        $this->siteDomain->update(['ssl_enabled_at' => now()]);

        try {
            $this->site->server->webServer()->syncSite($this->site);
        } catch (\Throwable $exception) {
            $this->siteDomain->update(['ssl_enabled_at' => null]);

            throw $exception;
        } finally {
            broadcast(new SiteDomainsUpdated($this->site));
        }
    }

    /**
     * Certbot runs every script in this directory after each successful renewal,
     * which is what keeps the copy nginx serves from expiring.
     */
    public static function deployHookPath(int $siteDomainId): string
    {
        return "/etc/letsencrypt/renewal-hooks/deploy/flitops-domain-{$siteDomainId}.sh";
    }

    public function failed(\Throwable $exception): void
    {
        $server = $this->site->server;

        ($server->team?->owner ?? $server->user)?->notify(
            new SslCertificateRequestFailed($this->siteDomain, $server->redactSecrets($exception->getMessage())),
        );
    }

    private function deployHookScript(string $lineage, string $certDir): string
    {
        return <<<SH
        #!/bin/sh
        [ "\$RENEWED_LINEAGE" = "{$lineage}" ] || exit 0
        [ -d {$certDir} ] || exit 0
        set -e
        cp "\$RENEWED_LINEAGE/fullchain.pem" {$certDir}/server.crt
        cp "\$RENEWED_LINEAGE/privkey.pem" {$certDir}/server.key
        chmod 644 {$certDir}/server.crt
        chmod 600 {$certDir}/server.key
        nginx -t && systemctl reload nginx
        SH;
    }

    private function readExpiry(string $certContents, string $hostname): ?DateTimeImmutable
    {
        $parsed = @openssl_x509_parse($certContents);

        if (! is_array($parsed) || ! isset($parsed['validTo_time_t'])) {
            Log::warning("Unable to parse SSL certificate expiry for {$hostname}");

            return null;
        }

        return new DateTimeImmutable('@'.$parsed['validTo_time_t']);
    }
}
