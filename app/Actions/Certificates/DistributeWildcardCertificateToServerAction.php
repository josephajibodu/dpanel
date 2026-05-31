<?php

namespace App\Actions\Certificates;

use App\Models\Certificate;
use App\Models\Server;
use App\Models\SiteDomain;
use App\Services\Ssh\SshConnection;
use App\Services\Ssh\SshService;
use Illuminate\Support\Facades\Log;

/**
 * Fans the wildcard certificate out to every free-domain `(site_id, domain_id)`
 * folder on the given server, then reloads nginx once at the end iff anything
 * actually changed. Idempotent.
 *
 * Pass an existing SshConnection to reuse it (avoids opening a second SSH
 * session). When no connection is supplied the action opens and closes its
 * own.
 */
class DistributeWildcardCertificateToServerAction
{
    public function __construct(
        private SshService $sshService,
        private SyncWildcardCertificateForDomainAction $sync,
    ) {}

    public function execute(Server $server, ?SshConnection $connection = null): bool
    {
        $wildcardDomain = '*.'.config('server.free_domain');
        $certificate = Certificate::firstWhere('domain', $wildcardDomain);

        if (! $certificate) {
            Log::info("No wildcard certificate yet for {$wildcardDomain}; skipping distribution to server {$server->id}.");

            return false;
        }

        $freeSuffix = '.'.config('server.free_domain');

        $domains = SiteDomain::query()
            ->whereHas('site', fn ($q) => $q->where('server_id', $server->id))
            ->where('hostname', 'like', '%'.$freeSuffix)
            ->get(['id', 'site_id', 'hostname']);

        if ($domains->isEmpty()) {
            return false;
        }

        $ownsConnection = $connection === null;
        $connection ??= $this->sshService->connect($server);
        $changedAny = false;

        try {
            foreach ($domains as $domain) {
                $changedAny = $this->sync->execute($connection, $domain->site_id, $domain->id) || $changedAny;
            }

            if ($changedAny) {
                // Both halves of `&&` must run under sudo. `&&` is parsed by
                // the outer shell, so a single `sudo` prefix only applies to
                // the first command; the second would otherwise run as the
                // SSH user, which polkit rejects with "Interactive
                // authentication required".
                $connection->exec('sudo nginx -t && sudo systemctl reload nginx');
            }
        } finally {
            if ($ownsConnection) {
                $connection->disconnect();
            }
        }

        if ($changedAny) {
            $certificate->update(['last_distribution_at' => now()]);
        }

        return $changedAny;
    }
}
