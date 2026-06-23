<?php

use App\Enums\SiteDomainType;
use App\Jobs\IssueSslForDomainJob;
use App\Jobs\VerifyCustomDomainJob;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function makeVerifyJob(SiteDomain $domain, array $aRecords): VerifyCustomDomainJob
{
    return new class($domain, $aRecords) extends VerifyCustomDomainJob
    {
        public function __construct(SiteDomain $siteDomain, private array $fakeRecords)
        {
            parent::__construct($siteDomain);
        }

        protected function resolveARecords(string $hostname): array
        {
            return $this->fakeRecords;
        }
    };
}

it('marks domain as verified and dispatches SSL job when A record matches server IP', function () {
    Queue::fake();

    $server = Server::factory()->create(['ip_address' => '203.0.113.10']);
    $site = Site::factory()->create(['server_id' => $server->id]);
    $domain = SiteDomain::factory()->for($site)->create([
        'type' => SiteDomainType::Custom,
        'hostname' => 'example.com',
        'verified_at' => null,
    ]);

    $job = makeVerifyJob($domain, [['ip' => '203.0.113.10']]);
    $job->handle();

    expect($domain->fresh()->verified_at)->not->toBeNull();

    Queue::assertPushed(IssueSslForDomainJob::class, fn ($j) => $j->siteDomain->id === $domain->id);
});

it('throws when the A record does not match the server IP', function () {
    Queue::fake();

    $server = Server::factory()->create(['ip_address' => '203.0.113.10']);
    $site = Site::factory()->create(['server_id' => $server->id]);
    $domain = SiteDomain::factory()->for($site)->create([
        'type' => SiteDomainType::Custom,
        'hostname' => 'example.com',
        'verified_at' => null,
    ]);

    $job = makeVerifyJob($domain, [['ip' => '198.51.100.1']]);

    expect(fn () => $job->handle())->toThrow(\RuntimeException::class);
    expect($domain->fresh()->verified_at)->toBeNull();

    Queue::assertNotPushed(IssueSslForDomainJob::class);
});

it('throws when no A records are found', function () {
    Queue::fake();

    $server = Server::factory()->create(['ip_address' => '203.0.113.10']);
    $site = Site::factory()->create(['server_id' => $server->id]);
    $domain = SiteDomain::factory()->for($site)->create([
        'type' => SiteDomainType::Custom,
        'hostname' => 'example.com',
        'verified_at' => null,
    ]);

    $job = makeVerifyJob($domain, []);

    expect(fn () => $job->handle())->toThrow(\RuntimeException::class);
    expect($domain->fresh()->verified_at)->toBeNull();

    Queue::assertNotPushed(IssueSslForDomainJob::class);
});

it('skips verification for already verified domains', function () {
    Queue::fake();

    $server = Server::factory()->create(['ip_address' => '203.0.113.10']);
    $site = Site::factory()->create(['server_id' => $server->id]);
    $verifiedAt = now()->subDay();
    $domain = SiteDomain::factory()->for($site)->create([
        'type' => SiteDomainType::Custom,
        'hostname' => 'example.com',
        'verified_at' => $verifiedAt,
    ]);

    $job = makeVerifyJob($domain, []);
    $job->handle();

    expect($domain->fresh()->verified_at->toDateString())->toBe($verifiedAt->toDateString());
    Queue::assertNotPushed(IssueSslForDomainJob::class);
});

it('skips verification for system domains', function () {
    Queue::fake();

    $server = Server::factory()->create(['ip_address' => '203.0.113.10']);
    $site = Site::factory()->create(['server_id' => $server->id]);
    $domain = SiteDomain::factory()->for($site)->create([
        'type' => SiteDomainType::System,
        'hostname' => 'app.flitops.xyz',
        'verified_at' => null,
    ]);

    $job = makeVerifyJob($domain, [['ip' => '203.0.113.10']]);
    $job->handle();

    expect($domain->fresh()->verified_at)->toBeNull();
    Queue::assertNotPushed(IssueSslForDomainJob::class);
});
