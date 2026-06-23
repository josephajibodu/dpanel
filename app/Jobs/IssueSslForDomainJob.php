<?php

namespace App\Jobs;

use App\Enums\NginxFileSection;
use App\Events\SiteDomainsUpdated;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Services\Ssh\SshService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

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
        $hostname = $this->siteDomain->hostname;
        $email = $server->user?->email ?? config('mail.from.address');

        $connection = $sshService->connect($server);

        try {
            // Install certbot if it wasn't present when the server was provisioned.
            $hasCertbot = trim($connection->exec('which certbot 2>/dev/null || true')) !== '';
            if (! $hasCertbot) {
                $connection->exec('sudo DEBIAN_FRONTEND=noninteractive apt-get install -y certbot', 300);
            }

            $certDir = "/etc/nginx/ssl/domains/{$this->site->id}/{$this->siteDomain->id}";

            $webRoot = $this->site->webRoot();
            $connection->exec('sudo mkdir -p '.escapeshellarg($webRoot).'/.well-known/acme-challenge');
            $connection->exec("sudo mkdir -p {$certDir}");

            // For apex domains with a www redirect, include www in the cert so that
            // both the primary hostname and the www redirect server block work over HTTPS.
            $parts = explode('.', $hostname);
            $isApex = count($parts) === 2 && ! str_starts_with($hostname, 'www.');
            $wwwRedirect = $this->siteDomain->www_redirect->value;
            $includeWww = $isApex && in_array($wwwRedirect, ['from_www', 'to_www'], true);

            $domainFlags = '-d '.escapeshellarg($hostname);
            if ($includeWww) {
                $domainFlags .= ' -d '.escapeshellarg('www.'.$hostname);
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

            $this->siteDomain->update(['ssl_enabled_at' => now()]);
            broadcast(new SiteDomainsUpdated($this->site));

            // Clear the stale main nginx file so SyncSiteNginxJob regenerates it with SSL.
            $this->site->nginxFiles()
                ->where('site_domain_id', $this->siteDomain->id)
                ->where('section', NginxFileSection::Main->value)
                ->delete();

            SyncSiteNginxJob::dispatch($this->site);
        } finally {
            $connection->disconnect();
        }
    }
}
