<?php

namespace App\Jobs;

use App\Enums\SiteDomainType;
use App\Events\SiteDomainsUpdated;
use App\Models\SiteDomain;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class VerifyCustomDomainJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $backoff = 30;

    public function __construct(public SiteDomain $siteDomain) {}

    public function handle(): void
    {
        if ($this->siteDomain->type !== SiteDomainType::Custom) {
            return;
        }

        if ($this->siteDomain->isVerified()) {
            return;
        }

        $this->siteDomain->loadMissing('site.server');
        $serverIp = $this->siteDomain->site->server->ip_address;

        if (! $serverIp) {
            throw new \RuntimeException('Server has no IP address configured.');
        }

        $records = $this->resolveARecords($this->siteDomain->hostname);

        $found = collect($records)->contains(function (array $record) use ($serverIp): bool {
            return ($record['ip'] ?? '') === $serverIp;
        });

        if (! $found) {
            throw new \RuntimeException("DNS A record for {$this->siteDomain->hostname} does not point to {$serverIp}. DNS may not have propagated yet.");
        }

        $this->siteDomain->update(['verified_at' => now()]);
        broadcast(new SiteDomainsUpdated($this->siteDomain->site));

        IssueSslForDomainJob::dispatch($this->siteDomain, $this->siteDomain->site);
    }

    protected function resolveARecords(string $hostname): array
    {
        return @dns_get_record($hostname, DNS_A) ?: [];
    }
}
