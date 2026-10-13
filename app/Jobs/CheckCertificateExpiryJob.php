<?php

namespace App\Jobs;

use App\Enums\SiteDomainStatus;
use App\Enums\SiteDomainType;
use App\Models\Certificate;
use App\Models\SiteDomain;
use App\Notifications\SslCertificateExpiring;
use App\Notifications\WildcardCertificateExpiring;
use App\Services\Certificates\TlsCertificateProbe;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Daily check that emails before an SSL certificate expiry breaks HTTPS.
 * Let's Encrypt certificates are due for renewal 30 days out, so one inside
 * the alert window means renewal isn't reaching clients.
 *
 * Expiry is read from what each server actually serves (TlsCertificateProbe),
 * because the stored expiry is only written at issuance and the served copy
 * can lag behind it. The stored value is the fallback when a server can't be
 * reached.
 *
 * Alerts escalate through ALERT_THRESHOLDS: each certificate is emailed once
 * per threshold it crosses, not daily, and the marker clears once a renewed
 * certificate takes it back out of the window.
 */
class CheckCertificateExpiryJob implements ShouldQueue
{
    use Queueable;

    /**
     * Days left at which an alert is sent; 0 means already expired.
     *
     * @var list<int>
     */
    public const ALERT_THRESHOLDS = [14, 7, 3, 1, 0];

    public int $tries = 1;

    public int $timeout = 600;

    public function handle(TlsCertificateProbe $probe): void
    {
        $this->checkCustomDomains($probe);
        $this->checkWildcardCertificate($probe);
    }

    /**
     * Custom domains each have their own Let's Encrypt certificate, so each
     * is alerted on separately, to the server's owner.
     */
    private function checkCustomDomains(TlsCertificateProbe $probe): void
    {
        SiteDomain::query()
            ->where('type', SiteDomainType::Custom)
            ->where('status', SiteDomainStatus::Active)
            ->where('is_enabled', true)
            ->whereNotNull('ssl_enabled_at')
            ->with('site.server.user', 'site.server.team.owner')
            ->each(function (SiteDomain $domain) use ($probe) {
                $server = $domain->site->server;
                $expiresAt = $this->servedExpiry($probe, $server->ip_address, $domain->hostname)
                    ?? $domain->ssl_expires_at;
                $threshold = $this->thresholdFor($expiresAt);
                $shouldAlert = $this->crossedNewThreshold($threshold, $domain->ssl_expiry_alert_threshold);

                $domain->fill([
                    'ssl_expires_at' => $expiresAt,
                    'ssl_expiry_alert_threshold' => $threshold,
                ]);

                if ($shouldAlert) {
                    ($server->team?->owner ?? $server->user)?->notify(new SslCertificateExpiring($domain));
                }

                // Saved after notifying, so an alert that fails to send is retried on the next run.
                $domain->save();
            });
    }

    /**
     * The wildcard certificate is renewed on the Flitops host and copied to
     * every server with a free-domain site. A failed copy or nginx reload
     * leaves that server serving the old certificate, so the earliest expiry
     * seen across the copies counts, alerted once to the operator.
     */
    private function checkWildcardCertificate(TlsCertificateProbe $probe): void
    {
        $certificate = Certificate::firstWhere('domain', '*.'.config('server.free_domain'));

        if (! $certificate) {
            return;
        }

        $earliestServed = SiteDomain::query()
            ->where('type', SiteDomainType::System)
            ->where('status', SiteDomainStatus::Active)
            ->where('is_enabled', true)
            ->with('site.server')
            ->get()
            ->map(fn (SiteDomain $domain) => [
                'hostname' => $domain->hostname,
                'expires_at' => $this->servedExpiry($probe, $domain->site->server->ip_address, $domain->hostname),
            ])
            ->filter(fn (array $served) => $served['expires_at'] !== null)
            ->sortBy(fn (array $served) => $served['expires_at']->getTimestamp())
            ->first();

        $staleHostname = $earliestServed !== null && $earliestServed['expires_at']->isBefore($certificate->expires_at)
            ? $earliestServed['hostname']
            : null;
        $expiresAt = $staleHostname !== null ? $earliestServed['expires_at'] : $certificate->expires_at;

        $threshold = $this->thresholdFor($expiresAt);

        if ($this->crossedNewThreshold($threshold, $certificate->expiry_alert_threshold)) {
            $this->alertOperator(new WildcardCertificateExpiring($certificate->domain, $expiresAt, $staleHostname));
        }

        $certificate->update(['expiry_alert_threshold' => $threshold]);
    }

    private function alertOperator(WildcardCertificateExpiring $notification): void
    {
        $alertEmail = config('server.alert_email');

        if (blank($alertEmail)) {
            Log::warning("Wildcard certificate {$notification->domain} expires {$notification->expiresAt}, but no alert was sent because ALERT_EMAIL is not set.");

            return;
        }

        Notification::route('mail', $alertEmail)->notify($notification);
    }

    private function servedExpiry(TlsCertificateProbe $probe, ?string $ipAddress, string $hostname): ?CarbonInterface
    {
        return $ipAddress ? $probe->expiresAt($ipAddress, $hostname) : null;
    }

    /**
     * The tightest alert threshold the certificate is inside, or null when it
     * is outside the alert window altogether.
     */
    private function thresholdFor(?CarbonInterface $expiresAt): ?int
    {
        if ($expiresAt === null) {
            return null;
        }

        $daysLeft = now()->diffInDays($expiresAt);
        $crossed = array_filter(self::ALERT_THRESHOLDS, fn (int $threshold) => $daysLeft <= $threshold);

        return $crossed === [] ? null : min($crossed);
    }

    /**
     * Alert only on moving into a tighter threshold than the one last seen,
     * so each threshold emails once however many days the job runs inside it.
     */
    private function crossedNewThreshold(?int $threshold, ?int $previousThreshold): bool
    {
        return $threshold !== null && ($previousThreshold === null || $threshold < $previousThreshold);
    }
}
