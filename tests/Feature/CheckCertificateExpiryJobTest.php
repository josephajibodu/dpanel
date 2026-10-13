<?php

use App\Jobs\CheckCertificateExpiryJob;
use App\Models\Certificate;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\Team;
use App\Models\User;
use App\Notifications\SslCertificateExpiring;
use App\Notifications\WildcardCertificateExpiring;
use App\Services\Certificates\TlsCertificateProbe;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    Config::set('server.free_domain', 'flitops.test');
    Config::set('server.alert_email', 'ops@example.com');

    $this->owner = User::factory()->create();
    $this->team = Team::factory()->forUser($this->owner)->create();
    $this->server = Server::factory()->forTeam($this->team)->create(['ip_address' => '203.0.113.10']);
    $this->site = Site::factory()->forServer($this->server)->create(['domain' => 'shop.example.com']);
    $this->domain = SiteDomain::query()->where('site_id', $this->site->id)->sole();
    $this->domain->update(['verified_at' => now(), 'ssl_enabled_at' => now()->subDays(80)]);
});

/**
 * Stub the TLS layer: each hostname serves a certificate expiring at the given
 * time, and any hostname not listed can't be reached.
 *
 * @param  array<string, CarbonImmutable>  $expiries
 */
function fakeServedCertificateExpiries(array $expiries): void
{
    $probe = Mockery::mock(TlsCertificateProbe::class);
    $probe->shouldReceive('expiresAt')
        ->andReturnUsing(fn (string $host, string $hostname) => $expiries[$hostname] ?? null);

    app()->instance(TlsCertificateProbe::class, $probe);
}

function runCertificateExpiryCheck(): void
{
    app()->call([new CheckCertificateExpiryJob, 'handle']);
}

describe('custom domains', function () {
    it('alerts the server owner when the served certificate is inside the warning window', function () {
        $servedExpiry = CarbonImmutable::now()->addDays(10);
        fakeServedCertificateExpiries(['shop.example.com' => $servedExpiry]);

        runCertificateExpiryCheck();

        Notification::assertSentTo($this->owner, SslCertificateExpiring::class, function (SslCertificateExpiring $notification) {
            return $notification->siteDomain->is($this->domain)
                && $notification->toMail($this->owner)->subject === 'SSL certificate expiring soon: shop.example.com';
        });
        expect($this->domain->fresh()->ssl_expires_at->getTimestamp())->toBe($servedExpiry->getTimestamp())
            ->and($this->domain->fresh()->ssl_expiry_alert_threshold)->toBe(14);
    });

    it('does not alert when the served certificate is outside the warning window', function () {
        fakeServedCertificateExpiries(['shop.example.com' => CarbonImmutable::now()->addDays(40)]);

        runCertificateExpiryCheck();

        Notification::assertNothingSent();
        expect($this->domain->fresh()->ssl_expiry_alert_threshold)->toBeNull();
    });

    it('alerts when the served certificate has already expired', function () {
        fakeServedCertificateExpiries(['shop.example.com' => CarbonImmutable::now()->subDay()]);

        runCertificateExpiryCheck();

        Notification::assertSentTo($this->owner, SslCertificateExpiring::class, function (SslCertificateExpiring $notification) {
            return $notification->toMail($this->owner)->subject === 'SSL certificate expired: shop.example.com';
        });
        expect($this->domain->fresh()->ssl_expiry_alert_threshold)->toBe(0);
    });

    it('alerts once per threshold instead of on every run', function () {
        fakeServedCertificateExpiries(['shop.example.com' => CarbonImmutable::now()->addDays(10)]);

        runCertificateExpiryCheck();
        $this->travel(1)->day();
        runCertificateExpiryCheck();
        $this->travel(1)->day();
        runCertificateExpiryCheck();

        Notification::assertSentToTimes($this->owner, SslCertificateExpiring::class, 1);

        $this->travel(2)->days();
        runCertificateExpiryCheck();

        Notification::assertSentToTimes($this->owner, SslCertificateExpiring::class, 2);
        expect($this->domain->fresh()->ssl_expiry_alert_threshold)->toBe(7);
    });

    it('resets the alert once the certificate is renewed, so the next expiry alerts again', function () {
        fakeServedCertificateExpiries(['shop.example.com' => CarbonImmutable::now()->addDays(10)]);
        runCertificateExpiryCheck();

        fakeServedCertificateExpiries(['shop.example.com' => CarbonImmutable::now()->addDays(90)]);
        runCertificateExpiryCheck();

        expect($this->domain->fresh()->ssl_expiry_alert_threshold)->toBeNull();
        Notification::assertSentToTimes($this->owner, SslCertificateExpiring::class, 1);

        $this->travel(80)->days();
        runCertificateExpiryCheck();

        Notification::assertSentToTimes($this->owner, SslCertificateExpiring::class, 2);
    });

    it('trusts the served certificate over a stale stored expiry', function () {
        $this->domain->update(['ssl_expires_at' => now()->addDays(5)]);
        $servedExpiry = CarbonImmutable::now()->addDays(80);
        fakeServedCertificateExpiries(['shop.example.com' => $servedExpiry]);

        runCertificateExpiryCheck();

        Notification::assertNothingSent();
        expect($this->domain->fresh()->ssl_expires_at->getTimestamp())->toBe($servedExpiry->getTimestamp());
    });

    it('alerts when the server still serves an older certificate than the stored one', function () {
        $this->domain->update(['ssl_expires_at' => now()->addDays(80)]);
        fakeServedCertificateExpiries(['shop.example.com' => CarbonImmutable::now()->addDays(5)]);

        runCertificateExpiryCheck();

        Notification::assertSentTo($this->owner, SslCertificateExpiring::class);
    });

    it('falls back to the stored expiry when the server cannot be reached', function () {
        $this->domain->update(['ssl_expires_at' => now()->addDays(5)]);
        fakeServedCertificateExpiries([]);

        runCertificateExpiryCheck();

        Notification::assertSentTo($this->owner, SslCertificateExpiring::class);
        expect($this->domain->fresh()->ssl_expiry_alert_threshold)->toBe(7);
    });

    it('ignores custom domains without SSL', function () {
        $this->domain->update(['ssl_enabled_at' => null, 'ssl_expires_at' => now()->addDays(5)]);
        fakeServedCertificateExpiries(['shop.example.com' => CarbonImmutable::now()->addDays(5)]);

        runCertificateExpiryCheck();

        Notification::assertNothingSent();
    });
});

describe('wildcard certificate', function () {
    beforeEach(function () {
        $this->domain->update(['ssl_enabled_at' => null]);
        $this->freeSite = Site::factory()->forServer($this->server)->create(['domain' => 'app.flitops.test']);
    });

    it('alerts the operator when the wildcard certificate is inside the warning window', function () {
        Certificate::factory()->freeDomainWildcard()->create(['expires_at' => now()->addDays(10)]);
        fakeServedCertificateExpiries([]);

        runCertificateExpiryCheck();

        Notification::assertSentOnDemand(WildcardCertificateExpiring::class, function (WildcardCertificateExpiring $notification, array $channels, object $notifiable) {
            return $notifiable->routes['mail'] === 'ops@example.com'
                && $notification->domain === '*.flitops.test'
                && $notification->staleHostname === null
                && $notification->toMail($notifiable)->subject === 'Wildcard certificate expiring soon: *.flitops.test';
        });
        Notification::assertNotSentTo($this->owner, SslCertificateExpiring::class);
    });

    it('alerts with the hostname when a server still serves an older copy than the renewed certificate', function () {
        Certificate::factory()->freeDomainWildcard()->create(['expires_at' => now()->addDays(80)]);
        $servedExpiry = CarbonImmutable::now()->addDays(5);
        fakeServedCertificateExpiries(['app.flitops.test' => $servedExpiry]);

        runCertificateExpiryCheck();

        Notification::assertSentOnDemand(WildcardCertificateExpiring::class, function (WildcardCertificateExpiring $notification, array $channels, object $notifiable) use ($servedExpiry) {
            return $notification->staleHostname === 'app.flitops.test'
                && $notification->expiresAt->getTimestamp() === $servedExpiry->getTimestamp()
                && str_contains(implode(' ', $notification->toMail($notifiable)->introLines), 'app.flitops.test');
        });
    });

    it('does not alert when the certificate and served copies are outside the warning window', function () {
        $expiresAt = CarbonImmutable::now()->addDays(80);
        Certificate::factory()->freeDomainWildcard()->create(['expires_at' => $expiresAt]);
        fakeServedCertificateExpiries(['app.flitops.test' => $expiresAt]);

        runCertificateExpiryCheck();

        Notification::assertNothingSent();
    });

    it('alerts once per threshold and again after renewal', function () {
        $certificate = Certificate::factory()->freeDomainWildcard()->create(['expires_at' => now()->addDays(10)]);
        fakeServedCertificateExpiries([]);

        runCertificateExpiryCheck();
        runCertificateExpiryCheck();

        Notification::assertSentOnDemandTimes(WildcardCertificateExpiring::class, 1);

        $certificate->update(['expires_at' => now()->addDays(90)]);
        runCertificateExpiryCheck();
        expect($certificate->fresh()->expiry_alert_threshold)->toBeNull();

        $certificate->update(['expires_at' => now()->subHour()]);
        runCertificateExpiryCheck();

        Notification::assertSentOnDemandTimes(WildcardCertificateExpiring::class, 2);
    });

    it('logs instead of alerting when no operator email is configured', function () {
        Config::set('server.alert_email', null);
        Log::spy();
        Certificate::factory()->freeDomainWildcard()->create(['expires_at' => now()->addDays(10)]);
        fakeServedCertificateExpiries([]);

        runCertificateExpiryCheck();

        Notification::assertNothingSent();
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'ALERT_EMAIL'));
    });
});
