<?php

use App\Jobs\CheckCertificateExpiryJob;
use App\Jobs\CollectAllServerMetricsJob;
use App\Jobs\CollectDueBackupsJob;
use App\Jobs\IssueWildcardCertificateJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('horizon:snapshot')->everyFiveMinutes();

// Issue/renew the free-domain wildcard certificate. Idempotent — the issuer
// short-circuits when the existing cert isn't due for renewal.
Schedule::job(new IssueWildcardCertificateJob)->dailyAt('03:30');

// Email before a served SSL certificate expires (i.e. renewal isn't reaching
// clients). Runs after the wildcard renewal so a fresh renewal is seen.
Schedule::job(new CheckCertificateExpiryJob)->dailyAt('09:00');

// Poll CPU/memory/disk stats for every active server.
Schedule::job(new CollectAllServerMetricsJob)->everyTenMinutes();

// Run any database backups that are due.
Schedule::job(new CollectDueBackupsJob)->hourly();
