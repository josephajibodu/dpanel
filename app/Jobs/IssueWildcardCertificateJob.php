<?php

namespace App\Jobs;

use App\Models\Certificate;
use App\Models\Server;
use App\Notifications\WildcardCertificateRenewalFailed;
use App\Services\Certificates\WildcardCertificateIssuer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Issues or renews the wildcard certificate for the free domain and, when the
 * cert was actually (re)issued, fans out distribution to every server hosting
 * a free-domain site.
 *
 * Idempotent end-to-end: a no-op when the cert is fresh; reuses the issuer's
 * own renewal-window check.
 */
class IssueWildcardCertificateJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 240;

    public function handle(WildcardCertificateIssuer $issuer): void
    {
        $domain = '*.'.config('server.free_domain');

        $previousRenewedAt = Certificate::where('domain', $domain)->value('last_renewed_at');

        $certificate = $issuer->issueOrRenew($domain);

        // Renew-window guard: only fan out distribution when the cert was
        // actually reissued (last_renewed_at advanced) — not on the cheap
        // "still fresh" no-op path.
        if ($previousRenewedAt !== null && $previousRenewedAt->equalTo($certificate->last_renewed_at)) {
            return;
        }

        Server::query()
            ->whereHas('sites.domains', fn ($q) => $q->where('hostname', 'like', '%.'.config('server.free_domain')))
            ->get()
            ->each(fn (Server $server) => DistributeWildcardCertificateJob::dispatch($server));
    }

    /**
     * Renewal is retried daily for as long as the certificate is due, so the
     * failure email goes out at most weekly — CheckCertificateExpiryJob
     * escalates separately as the expiry itself gets close.
     */
    public function failed(\Throwable $exception): void
    {
        $domain = '*.'.config('server.free_domain');
        $alertEmail = config('server.alert_email');

        if (blank($alertEmail)) {
            Log::warning("Wildcard certificate renewal for {$domain} failed, but no alert was sent because ALERT_EMAIL is not set.");

            return;
        }

        if (! Cache::add('wildcard-certificate-renewal-failed-notified', true, now()->addWeek())) {
            return;
        }

        Notification::route('mail', $alertEmail)
            ->notify(new WildcardCertificateRenewalFailed($domain, $exception->getMessage()));
    }
}
