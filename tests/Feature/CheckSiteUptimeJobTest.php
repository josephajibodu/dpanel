<?php

use App\Enums\ServerStatus;
use App\Enums\UptimeStatus;
use App\Jobs\CheckSiteUptimeJob;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteIncident;
use App\Models\Team;
use App\Models\User;
use App\Notifications\SiteRecovered;
use App\Notifications\SiteWentDown;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    Notification::fake();

    $this->user = User::factory()->create();
    $this->team = Team::factory()->forUser($this->user)->create();
    $this->server = Server::factory()->forTeam($this->team)->create(['status' => ServerStatus::Active]);
    $this->site = Site::factory()->forServer($this->server)->deployed()->create();
});

it('marks a site up after a successful check', function () {
    Http::fake(['*' => Http::response('OK', 200)]);

    CheckSiteUptimeJob::dispatchSync($this->site);

    $this->site->refresh();
    expect($this->site->uptime_status)->toBe(UptimeStatus::Up)
        ->and($this->site->uptime_checked_at)->not->toBeNull()
        ->and($this->site->uptime_consecutive_failures)->toBe(0);

    Http::assertSent(fn (Request $request) => $request->url() === "http://{$this->site->domain}");
    Notification::assertNothingSent();
});

it('checks over https when the primary domain has ssl', function () {
    Http::fake(['*' => Http::response('OK', 200)]);
    $this->site->primaryDomain->update(['ssl_enabled_at' => now()]);

    CheckSiteUptimeJob::dispatchSync($this->site);

    Http::assertSent(fn (Request $request) => $request->url() === "https://{$this->site->domain}");
});

it('does not alert on a single failed check', function () {
    $this->site->update(['uptime_status' => UptimeStatus::Up]);
    Http::fake(['*' => Http::sequence()->push('Bad Gateway', 502)->push('OK', 200)]);

    CheckSiteUptimeJob::dispatchSync($this->site);

    $this->site->refresh();
    expect($this->site->uptime_status)->toBe(UptimeStatus::Up)
        ->and($this->site->uptime_consecutive_failures)->toBe(1)
        ->and($this->site->incidents()->count())->toBe(0);

    CheckSiteUptimeJob::dispatchSync($this->site);

    $this->site->refresh();
    expect($this->site->uptime_status)->toBe(UptimeStatus::Up)
        ->and($this->site->uptime_consecutive_failures)->toBe(0);

    Notification::assertNothingSent();
});

it('marks a site down after two consecutive failures and alerts the team owner', function () {
    $this->site->update(['uptime_status' => UptimeStatus::Up]);
    Http::fake(['*' => Http::response('Bad Gateway', 502)]);

    CheckSiteUptimeJob::dispatchSync($this->site);
    CheckSiteUptimeJob::dispatchSync($this->site);

    $this->site->refresh();
    $incident = $this->site->openIncident;

    expect($this->site->uptime_status)->toBe(UptimeStatus::Down)
        ->and($this->site->uptime_consecutive_failures)->toBe(2)
        ->and($incident)->not->toBeNull()
        ->and($incident->reason)->toBe('HTTP 502 response')
        ->and($incident->started_at)->not->toBeNull();

    Notification::assertSentToTimes($this->user, SiteWentDown::class, 1);
    Notification::assertSentTo($this->user, SiteWentDown::class, fn (SiteWentDown $notification) => $notification->incident->is($incident));
});

it('does not alert again or open another incident while a site stays down', function () {
    Http::fake(['*' => Http::response('Service Unavailable', 503)]);

    CheckSiteUptimeJob::dispatchSync($this->site);
    CheckSiteUptimeJob::dispatchSync($this->site);
    CheckSiteUptimeJob::dispatchSync($this->site);
    CheckSiteUptimeJob::dispatchSync($this->site);

    $this->site->refresh();
    expect($this->site->uptime_status)->toBe(UptimeStatus::Down)
        ->and($this->site->uptime_consecutive_failures)->toBe(4)
        ->and($this->site->incidents()->count())->toBe(1);

    Notification::assertSentToTimes($this->user, SiteWentDown::class, 1);
});

it('resolves the incident and sends a recovery notice when the site comes back', function () {
    $this->site->update([
        'uptime_status' => UptimeStatus::Down,
        'uptime_consecutive_failures' => 3,
    ]);
    $incident = SiteIncident::factory()->forSite($this->site)->create();
    Http::fake(['*' => Http::response('OK', 200)]);

    CheckSiteUptimeJob::dispatchSync($this->site);

    $this->site->refresh();
    expect($this->site->uptime_status)->toBe(UptimeStatus::Up)
        ->and($this->site->uptime_consecutive_failures)->toBe(0)
        ->and($incident->fresh()->resolved_at)->not->toBeNull()
        ->and($this->site->openIncident)->toBeNull();

    Notification::assertSentTo($this->user, SiteRecovered::class, fn (SiteRecovered $notification) => $notification->incident->is($incident));
    Notification::assertNotSentTo($this->user, SiteWentDown::class);
});

it('counts connection errors and timeouts as failures', function (string $message) {
    Http::fake(['*' => Http::failedConnection($message)]);

    CheckSiteUptimeJob::dispatchSync($this->site);
    CheckSiteUptimeJob::dispatchSync($this->site);

    $this->site->refresh();
    expect($this->site->uptime_status)->toBe(UptimeStatus::Down)
        ->and($this->site->openIncident->reason)->toContain($message);
})->with([
    'connection refused' => 'cURL error 7: Failed to connect to the host: Connection refused',
    'timeout' => 'cURL error 28: Operation timed out after 10001 milliseconds with 0 bytes received',
]);

it('treats non-5xx responses as up', function (int $status) {
    Http::fake(['*' => Http::response('', $status)]);

    CheckSiteUptimeJob::dispatchSync($this->site);
    CheckSiteUptimeJob::dispatchSync($this->site);

    $this->site->refresh();
    expect($this->site->uptime_status)->toBe(UptimeStatus::Up)
        ->and($this->site->uptime_consecutive_failures)->toBe(0);
})->with([200, 204, 401, 403, 404]);

it('renders the down and recovery emails', function () {
    $incident = SiteIncident::factory()->forSite($this->site)->create([
        'reason' => 'HTTP 502 response',
        'started_at' => now()->subMinutes(15),
        'resolved_at' => now(),
    ]);
    $siteUrl = route('servers.sites.show', [$this->team, $this->server, $this->site]);

    $downMail = (new SiteWentDown($incident))->toMail($this->user);
    $recoveredMail = (new SiteRecovered($incident))->toMail($this->user);

    expect($downMail->subject)->toBe("Site down: {$this->site->domain}")
        ->and($downMail->introLines)->toContain('**Reason:** HTTP 502 response')
        ->and($downMail->actionUrl)->toBe($siteUrl)
        ->and($recoveredMail->subject)->toBe("Site recovered: {$this->site->domain}")
        ->and($recoveredMail->introLines)->toContain('It was down for 15 minutes.')
        ->and($recoveredMail->actionUrl)->toBe($siteUrl);
});

it('is unique per site with a single try', function () {
    $job = new CheckSiteUptimeJob($this->site);

    expect($job->uniqueId())->toBe((string) $this->site->id)
        ->and($job->tries)->toBe(1);
});
