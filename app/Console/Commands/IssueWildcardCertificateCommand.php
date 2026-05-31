<?php

namespace App\Console\Commands;

use App\Jobs\IssueWildcardCertificateJob;
use Illuminate\Console\Command;

class IssueWildcardCertificateCommand extends Command
{
    protected $signature = 'flitops:wildcard:issue';

    protected $description = 'Issue or renew the free-domain wildcard certificate (idempotent).';

    public function handle(IssueWildcardCertificateJob $job): int
    {
        $domain = '*.'.config('server.free_domain');

        $this->components->info("Issuing/renewing wildcard certificate for {$domain}...");

        $job->handle(app(\App\Services\Certificates\WildcardCertificateIssuer::class));

        $this->components->info('Done.');

        return self::SUCCESS;
    }
}
