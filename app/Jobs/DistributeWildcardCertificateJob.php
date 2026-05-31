<?php

namespace App\Jobs;

use App\Actions\Certificates\DistributeWildcardCertificateToServerAction;
use App\Models\Server;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Async wrapper around DistributeWildcardCertificateToServerAction, used by
 * the renewal fan-out: when IssueWildcardCertificateJob re-issues the
 * wildcard cert, one of these is dispatched per server hosting a free-domain
 * site.
 *
 * The per-site-create path does NOT use this job — the site provisioner
 * invokes SyncWildcardCertificateForDomainAction synchronously instead, so
 * the cert is guaranteed to be on disk before `nginx -t` runs.
 */
class DistributeWildcardCertificateJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public Server $server) {}

    public function handle(DistributeWildcardCertificateToServerAction $distribute): void
    {
        $distribute->execute($this->server);
    }
}
