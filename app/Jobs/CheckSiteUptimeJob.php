<?php

namespace App\Jobs;

use App\Enums\UptimeStatus;
use App\Models\Site;
use App\Notifications\SiteRecovered;
use App\Notifications\SiteWentDown;
use App\Services\Nginx\NginxConfigService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;

/**
 * HTTP-checks a site's primary domain and records the result. The site is
 * only marked down (opening an incident and emailing the owner) after
 * FAILURE_THRESHOLD consecutive failures, so a single blip doesn't alert;
 * the first successful check afterwards resolves the incident and sends a
 * recovery email.
 */
class CheckSiteUptimeJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const FAILURE_THRESHOLD = 2;

    public int $tries = 1;

    public int $timeout = 60;

    public int $uniqueFor = 300;

    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public Site $site,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->site->id;
    }

    public function handle(NginxConfigService $nginxConfig): void
    {
        $domain = $this->site->primaryDomain;

        if (! $domain) {
            return;
        }

        $scheme = $nginxConfig->shouldUseSsl($domain) ? 'https' : 'http';
        $failureReason = $this->probe("{$scheme}://{$domain->hostname}");

        if ($failureReason === null) {
            $this->recordSuccess();
        } else {
            $this->recordFailure($failureReason);
        }
    }

    /**
     * Request the URL and return why it counts as down, or null when it's up.
     * Connection errors, timeouts and 5xx responses are down; anything else,
     * including a 4xx, means the site is answering.
     */
    private function probe(string $url): ?string
    {
        try {
            $response = Http::connectTimeout(5)
                ->timeout(10)
                ->withUserAgent('Flitops Uptime Monitor')
                ->get($url);
        } catch (HttpClientException $e) {
            return $e->getMessage();
        }

        return $response->serverError() ? "HTTP {$response->status()} response" : null;
    }

    private function recordSuccess(): void
    {
        $openIncident = $this->site->openIncident;

        $this->site->update([
            'uptime_status' => UptimeStatus::Up,
            'uptime_checked_at' => now(),
            'uptime_consecutive_failures' => 0,
        ]);

        if (! $openIncident) {
            return;
        }

        $openIncident->update(['resolved_at' => now()]);

        $this->notifyOwner(new SiteRecovered($openIncident));
    }

    private function recordFailure(string $reason): void
    {
        $this->site->update([
            'uptime_checked_at' => now(),
            'uptime_consecutive_failures' => $this->site->uptime_consecutive_failures + 1,
        ]);

        if ($this->site->uptime_status === UptimeStatus::Down
            || $this->site->uptime_consecutive_failures < self::FAILURE_THRESHOLD) {
            return;
        }

        $this->site->update(['uptime_status' => UptimeStatus::Down]);

        $incident = $this->site->incidents()->create([
            'reason' => $reason,
            'started_at' => now(),
        ]);

        $this->notifyOwner(new SiteWentDown($incident));
    }

    private function notifyOwner(Notification $notification): void
    {
        $server = $this->site->server;
        $owner = $server->team?->owner ?? $server->user;

        $owner?->notify($notification);
    }
}
