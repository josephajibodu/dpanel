<?php

use App\Jobs\DistributeWildcardCertificateJob;
use App\Jobs\IssueWildcardCertificateJob;
use App\Models\Certificate;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Notifications\WildcardCertificateRenewalFailed;
use App\Services\Certificates\WildcardCertificateIssuer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    Config::set('server.free_domain', 'flitops.test');
});

it('dispatches a distribution job for every server hosting a free-domain site after a fresh issuance', function () {
    $serverWithFreeSite = Server::factory()->create();
    $freeSite = Site::factory()->forServer($serverWithFreeSite)->create(['domain' => 'a.flitops.test']);
    SiteDomain::query()->where('site_id', $freeSite->id)->update(['hostname' => 'a.flitops.test']);

    $serverWithCustomOnly = Server::factory()->create();
    $customSite = Site::factory()->forServer($serverWithCustomOnly)->create(['domain' => 'example.com']);
    SiteDomain::query()->where('site_id', $customSite->id)->update(['hostname' => 'example.com']);

    $issuer = Mockery::mock(WildcardCertificateIssuer::class);
    $issuer->shouldReceive('issueOrRenew')
        ->once()
        ->with('*.flitops.test')
        ->andReturnUsing(fn () => Certificate::factory()->create([
            'domain' => '*.flitops.test',
            'last_renewed_at' => now(),
        ]));

    (new IssueWildcardCertificateJob)->handle($issuer);

    Queue::assertPushed(DistributeWildcardCertificateJob::class, fn ($job) => $job->server->is($serverWithFreeSite));
    Queue::assertNotPushed(DistributeWildcardCertificateJob::class, fn ($job) => $job->server->is($serverWithCustomOnly));
});

it('does not dispatch distribution when the existing certificate is still fresh', function () {
    $server = Server::factory()->create();
    $freeSite = Site::factory()->forServer($server)->create(['domain' => 'a.flitops.test']);
    SiteDomain::query()->where('site_id', $freeSite->id)->update(['hostname' => 'a.flitops.test']);

    $renewedAt = now()->subHour();
    $existing = Certificate::factory()->create([
        'domain' => '*.flitops.test',
        'last_renewed_at' => $renewedAt,
    ]);

    $issuer = Mockery::mock(WildcardCertificateIssuer::class);
    $issuer->shouldReceive('issueOrRenew')
        ->once()
        ->with('*.flitops.test')
        ->andReturn($existing);

    (new IssueWildcardCertificateJob)->handle($issuer);

    Queue::assertNotPushed(DistributeWildcardCertificateJob::class);
});

it('does not dispatch anything when no server hosts a free-domain site', function () {
    $server = Server::factory()->create();
    $customSite = Site::factory()->forServer($server)->create(['domain' => 'example.com']);
    SiteDomain::query()->where('site_id', $customSite->id)->update(['hostname' => 'example.com']);

    $issuer = Mockery::mock(WildcardCertificateIssuer::class);
    $issuer->shouldReceive('issueOrRenew')
        ->once()
        ->with('*.flitops.test')
        ->andReturnUsing(fn () => Certificate::factory()->create([
            'domain' => '*.flitops.test',
            'last_renewed_at' => now(),
        ]));

    (new IssueWildcardCertificateJob)->handle($issuer);

    Queue::assertNotPushed(DistributeWildcardCertificateJob::class);
});

it('emails the operator when renewal fails', function () {
    Notification::fake();
    Config::set('server.alert_email', 'ops@example.com');

    (new IssueWildcardCertificateJob)->failed(new RuntimeException('acme.sh failed (exit 1): invalid token'));

    Notification::assertSentOnDemand(WildcardCertificateRenewalFailed::class, function (WildcardCertificateRenewalFailed $notification, array $channels, object $notifiable) {
        return $notifiable->routes['mail'] === 'ops@example.com'
            && $notification->domain === '*.flitops.test'
            && str_contains($notification->errorMessage, 'invalid token')
            && $notification->toMail($notifiable)->subject === 'Wildcard certificate renewal failed: *.flitops.test';
    });
});

it('emails about repeated renewal failures at most once a week', function () {
    Notification::fake();
    Config::set('server.alert_email', 'ops@example.com');

    (new IssueWildcardCertificateJob)->failed(new RuntimeException('acme.sh failed'));
    $this->travel(1)->day();
    (new IssueWildcardCertificateJob)->failed(new RuntimeException('acme.sh failed'));

    Notification::assertSentOnDemandTimes(WildcardCertificateRenewalFailed::class, 1);

    $this->travel(7)->days();
    (new IssueWildcardCertificateJob)->failed(new RuntimeException('acme.sh failed'));

    Notification::assertSentOnDemandTimes(WildcardCertificateRenewalFailed::class, 2);
});

it('logs instead of emailing a renewal failure when no operator email is configured', function () {
    Notification::fake();
    Log::spy();
    Config::set('server.alert_email', null);

    (new IssueWildcardCertificateJob)->failed(new RuntimeException('acme.sh failed'));

    Notification::assertNothingSent();
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'ALERT_EMAIL'));
});
