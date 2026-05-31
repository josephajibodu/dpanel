<?php

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
