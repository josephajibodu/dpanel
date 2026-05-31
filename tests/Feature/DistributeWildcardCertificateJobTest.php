<?php

use App\Actions\Certificates\DistributeWildcardCertificateToServerAction;
use App\Actions\Certificates\SyncWildcardCertificateForDomainAction;
use App\Jobs\DistributeWildcardCertificateJob;
use App\Models\Certificate;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Services\Ssh\SshConnection;
use App\Services\Ssh\SshService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;

uses(RefreshDatabase::class);

function makeDistributeAction(SshService $sshService): DistributeWildcardCertificateToServerAction
{
    return new DistributeWildcardCertificateToServerAction(
        $sshService,
        new SyncWildcardCertificateForDomainAction,
    );
}

beforeEach(function () {
    Config::set('server.free_domain', 'flitops.test');

    $this->certDir = sys_get_temp_dir().'/distribute-cert-'.bin2hex(random_bytes(4));
    @mkdir($this->certDir, 0700, true);

    $this->certContent = 'FAKE-CERT-'.bin2hex(random_bytes(16));
    $this->keyContent = 'FAKE-KEY-'.bin2hex(random_bytes(16));
    file_put_contents("{$this->certDir}/server.crt", $this->certContent);
    file_put_contents("{$this->certDir}/server.key", $this->keyContent);

    $this->cert = Certificate::factory()->create([
        'domain' => '*.flitops.test',
        'certificate_path' => "{$this->certDir}/server.crt",
        'private_key_path' => "{$this->certDir}/server.key",
        'last_distribution_at' => null,
    ]);

    $this->server = Server::factory()->create();
});

afterEach(function () {
    @unlink("{$this->certDir}/server.crt");
    @unlink("{$this->certDir}/server.key");
    @rmdir($this->certDir);
});

function makeFreeDomainSite(Server $server, string $hostname): SiteDomain
{
    Site::factory()->forServer($server)->create(['domain' => $hostname]);

    return SiteDomain::query()->where('hostname', $hostname)->firstOrFail();
}

it('uploads cert + key for each free-domain site and reloads nginx when content differs', function () {
    $d1 = makeFreeDomainSite($this->server, 'a.flitops.test');
    $d2 = makeFreeDomainSite($this->server, 'b.flitops.test');

    $execCalls = [];
    $uploadDestinations = [];

    $connection = Mockery::mock(SshConnection::class)->makePartial();
    $connection->shouldReceive('exec')->andReturnUsing(function (string $cmd) use (&$execCalls) {
        $execCalls[] = $cmd;

        // sha256sum probes return empty → treated as "no remote file" → triggers upload.
        return '';
    });
    $connection->shouldReceive('upload')->andReturnUsing(function (string $content, string $path) use (&$uploadDestinations) {
        $uploadDestinations[] = $path;
    });
    $connection->shouldReceive('disconnect')->andReturnNull();

    $service = Mockery::mock(SshService::class);
    $service->shouldReceive('connect')->once()->andReturn($connection);

    (new DistributeWildcardCertificateJob($this->server))->handle(makeDistributeAction($service));

    // 2 uploads per (site, domain) — cert + key — across 2 domains.
    expect($uploadDestinations)->toHaveCount(4);
    foreach ($uploadDestinations as $tempPath) {
        expect($tempPath)->toStartWith('/tmp/wildcard_');
    }

    // sudo install lands them at the Forge-style ID-based paths.
    $installs = collect($execCalls)->filter(fn ($c) => str_starts_with($c, 'sudo install '))->values();
    expect($installs)->toHaveCount(4);
    expect($installs->contains(fn ($c) => str_contains($c, "/etc/nginx/ssl/domains/{$d1->site_id}/{$d1->id}/server.crt") && str_contains($c, '-m 0644')))->toBeTrue();
    expect($installs->contains(fn ($c) => str_contains($c, "/etc/nginx/ssl/domains/{$d1->site_id}/{$d1->id}/server.key") && str_contains($c, '-m 0600')))->toBeTrue();
    expect($installs->contains(fn ($c) => str_contains($c, "/etc/nginx/ssl/domains/{$d2->site_id}/{$d2->id}/server.crt")))->toBeTrue();
    expect($installs->contains(fn ($c) => str_contains($c, "/etc/nginx/ssl/domains/{$d2->site_id}/{$d2->id}/server.key")))->toBeTrue();

    // Nginx is reloaded exactly once at the end, only because something changed.
    expect(collect($execCalls)->contains(fn ($c) => $c === 'sudo nginx -t && sudo systemctl reload nginx'))->toBeTrue();

    expect($this->cert->fresh()->last_distribution_at)->not->toBeNull();
});

it('skips upload and skips the nginx reload when the remote cert hash already matches', function () {
    makeFreeDomainSite($this->server, 'a.flitops.test');

    $localHash = hash('sha256', $this->certContent);

    $execCalls = [];

    $connection = Mockery::mock(SshConnection::class)->makePartial();
    $connection->shouldReceive('exec')->andReturnUsing(function (string $cmd) use (&$execCalls, $localHash) {
        $execCalls[] = $cmd;

        return str_contains($cmd, 'sha256sum') ? $localHash."\n" : '';
    });
    $connection->shouldReceive('upload')->never();
    $connection->shouldReceive('disconnect')->andReturnNull();

    $service = Mockery::mock(SshService::class);
    $service->shouldReceive('connect')->once()->andReturn($connection);

    (new DistributeWildcardCertificateJob($this->server))->handle(makeDistributeAction($service));

    expect(collect($execCalls)->contains(fn ($c) => str_contains($c, 'reload nginx')))->toBeFalse();
    expect($this->cert->fresh()->last_distribution_at)->toBeNull();
});

it('does not touch the server when no wildcard certificate exists yet', function () {
    $this->cert->delete();
    makeFreeDomainSite($this->server, 'a.flitops.test');

    $service = Mockery::mock(SshService::class);
    $service->shouldNotReceive('connect');

    (new DistributeWildcardCertificateJob($this->server))->handle(makeDistributeAction($service));
});

it('does not touch the server when it hosts no free-domain sites', function () {
    Site::factory()->forServer($this->server)->create(['domain' => 'example.com']);

    $service = Mockery::mock(SshService::class);
    $service->shouldNotReceive('connect');

    (new DistributeWildcardCertificateJob($this->server))->handle(makeDistributeAction($service));
});
