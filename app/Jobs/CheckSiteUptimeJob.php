<?php

namespace App\Jobs;

use App\Enums\UptimeStatus;
use App\Models\Site;
use App\Notifications\SiteRecovered;
use App\Notifications\SiteWentDown;
use App\Services\Nginx\NginxConfigService;
use App\Services\WebServer\SiteErrorLogReader;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * HTTP-checks a site's primary domain and records the result. The site is
 * only marked down (opening an incident and emailing the owner) after
 * FAILURE_THRESHOLD consecutive failures, so a single blip doesn't alert;
 * the first successful check afterwards resolves the incident and sends a
 * recovery email. The down email includes the site's last few error log
 * lines when they can be read.
 */
class CheckSiteUptimeJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const FAILURE_THRESHOLD = 2;

    public const ALERT_ERROR_LOG_LINES = 10;

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

    public function handle(NginxConfigService $nginxConfig, SiteErrorLogReader $errorLogReader): void
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
            $this->recordFailure($failureReason, $errorLogReader);
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
            return $this->describeConnectionFailure($e->getMessage());
        }

        return $response->serverError()
            ? "The site responded with a server error (HTTP {$response->status()})."
            : null;
    }

    /**
     * Turn a raw cURL error into a sentence the site owner can act on.
     * Unrecognised errors are returned as they are.
     */
    private function describeConnectionFailure(string $message): string
    {
        if (! preg_match('/cURL error (\d+)/', $message, $matches)) {
            return $message;
        }

        return match ((int) $matches[1]) {
            6 => "The domain name couldn't be looked up. Its DNS record may be missing, or may not have spread yet if it was added recently.",
            7 => 'The server refused the connection. The web server (Nginx) may be stopped, or a firewall may be blocking it.',
            28 => "The server didn't respond within 10 seconds.",
            35 => "A secure (HTTPS) connection couldn't be set up with the server.",
            51, 60 => "The site's SSL certificate isn't valid. It may have expired or may not cover this domain.",
            52 => 'The server closed the connection without sending a response.',
            56 => 'The connection to the server was cut off part-way through.',
            default => $message,
        };
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

    private function recordFailure(string $reason, SiteErrorLogReader $errorLogReader): void
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

        $this->notifyOwner(new SiteWentDown($incident, $this->recentErrorLogLines($errorLogReader)));
    }

    /**
     * The site's last few error log lines, or none when they can't be read,
     * so a server that's unreachable over SSH never holds up the alert.
     *
     * @return array<int, string>
     */
    private function recentErrorLogLines(SiteErrorLogReader $errorLogReader): array
    {
        try {
            return $errorLogReader->recentLines($this->site, self::ALERT_ERROR_LOG_LINES);
        } catch (\Throwable $e) {
            Log::warning("Couldn't read the error log for the down alert of site {$this->site->id}: {$e->getMessage()}");

            return [];
        }
    }

    private function notifyOwner(Notification $notification): void
    {
        $server = $this->site->server;
        $owner = $server->team?->owner ?? $server->user;

        $owner?->notify($notification);
    }
}
