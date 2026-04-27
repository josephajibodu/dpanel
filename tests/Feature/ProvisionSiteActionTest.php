<?php

use App\Actions\Sites\ProvisionSiteAction;
use App\Enums\ProjectType;
use App\Enums\ServerStatus;
use App\Enums\SiteProvisioningStep;
use App\Enums\SiteStatus;
use App\Events\ServerSitesUpdated;
use App\Jobs\DeploySiteJob;
use App\Models\Server;
use App\Models\Site;
use App\Services\Ssh\SshConnection;
use App\Services\Ssh\SshService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Event::fake([ServerSitesUpdated::class]);
    Queue::fake([DeploySiteJob::class]);
});

function mockSshConnection(array &$execCalls = []): SshConnection
{
    $mock = \Mockery::mock(SshConnection::class)->makePartial();
    $mock->shouldReceive('exec')
        ->andReturnUsing(function (string $cmd) use (&$execCalls) {
            $execCalls[] = $cmd;

            return str_contains($cmd, 'nginx -t') ? 'syntax is ok' : '';
        });
    $mock->shouldReceive('disconnect')->andReturn(null);

    return $mock;
}

function mockSshService(SshConnection $connection, ?int $serverId = null): SshService
{
    $mock = \Mockery::mock(SshService::class);
    $matcher = $serverId
        ? \Mockery::on(fn ($s) => $s->id === $serverId)
        : \Mockery::any();

    $mock->shouldReceive('connect')
        ->once()
        ->with($matcher)
        ->andReturn($connection);

    return $mock;
}

// --------------------------------------------------------------------------
// ProvisionSiteAction orchestration tests
// --------------------------------------------------------------------------

it('provisions a placeholder StaticHtml site and sets status to provisioned', function () {
    $server = Server::factory()->create(['status' => ServerStatus::Active]);

    $site = Site::factory()
        ->forServer($server)
        ->pending()
        ->create([
            'repository' => null,
            'source_control_account_id' => null,
            'project_type' => ProjectType::StaticHtml,
            'directory' => '/',
        ]);
    $site->deployScript()->create(['script' => 'echo "placeholder"']);

    $mockConnection = mockSshConnection();
    $this->app->instance(SshService::class, mockSshService($mockConnection, $server->id));

    app(ProvisionSiteAction::class)->execute($site);

    $site->refresh();
    expect($site->status)->toBe(SiteStatus::Provisioned);
    expect($site->provisioning_step)->toBeNull();
});

it('provisions a Laravel site through the infra-only steps', function () {
    $server = Server::factory()->create(['status' => ServerStatus::Active]);

    $site = Site::factory()
        ->forServer($server)
        ->pending()
        ->create([
            'repository' => null,
            'source_control_account_id' => null,
            'project_type' => ProjectType::Laravel,
        ]);
    $site->deployScript()->create(['script' => 'echo "laravel"']);

    $execCalls = [];
    $mockConnection = mockSshConnection($execCalls);
    $this->app->instance(SshService::class, mockSshService($mockConnection));

    $action = app(ProvisionSiteAction::class);
    $action->execute($site);

    $site->refresh();
    expect($site->status)->toBe(SiteStatus::Provisioned);
    expect($execCalls)->not->toBeEmpty();
    expect(collect($execCalls)->contains(fn ($c) => str_contains($c, 'mkdir -p')))->toBeTrue();
});

it('sets site status to failed when a step throws', function () {
    $server = Server::factory()->create(['status' => ServerStatus::Active]);

    $site = Site::factory()
        ->forServer($server)
        ->pending()
        ->create([
            'repository' => null,
            'source_control_account_id' => null,
            'project_type' => ProjectType::StaticHtml,
            'directory' => '/',
        ]);
    $site->deployScript()->create(['script' => 'echo "test"']);

    $mockConnection = \Mockery::mock(SshConnection::class)->makePartial();
    $mockConnection->shouldReceive('exec')
        ->andThrow(new \RuntimeException('SSH connection lost'));
    $mockConnection->shouldReceive('disconnect')->andReturn(null);

    $sshService = \Mockery::mock(SshService::class);
    $sshService->shouldReceive('connect')->once()->andReturn($mockConnection);
    $this->app->instance(SshService::class, $sshService);

    expect(fn () => app(ProvisionSiteAction::class)->execute($site))
        ->toThrow(\RuntimeException::class, 'SSH connection lost');

    $site->refresh();
    expect($site->status)->toBe(SiteStatus::Failed);
});

// --------------------------------------------------------------------------
// Enum step counts
// --------------------------------------------------------------------------

it('uses infra-only steps for every project type', function () {
    $expected = [
        SiteProvisioningStep::Initializing,
        SiteProvisioningStep::ConfiguringNginx,
        SiteProvisioningStep::CloningRepository,
        SiteProvisioningStep::CreatingEnvironmentFile,
        SiteProvisioningStep::MakingFinalTouches,
    ];

    foreach (ProjectType::cases() as $projectType) {
        $steps = SiteProvisioningStep::enumCasesForProjectType($projectType);

        expect($steps)->toHaveCount(5)
            ->and($steps)->toBe($expected)
            ->and($steps)->not->toContain(SiteProvisioningStep::InstallingDependencies)
            ->and($steps)->not->toContain(SiteProvisioningStep::BuildingFrontendAssets)
            ->and($steps)->not->toContain(SiteProvisioningStep::RunningDatabaseMigrations);
    }
});

// --------------------------------------------------------------------------
// Per-project-type provisioner tests
// --------------------------------------------------------------------------

it('laravel provisioner does not run composer install during provisioning', function () {
    $server = Server::factory()->create(['status' => ServerStatus::Active]);
    $site = Site::factory()->forServer($server)->pending()->create([
        'repository' => null,
        'source_control_account_id' => null,
        'project_type' => ProjectType::Laravel,
    ]);

    $execCalls = [];
    $mockConnection = mockSshConnection($execCalls);
    $this->app->instance(SshService::class, mockSshService($mockConnection));

    app(ProvisionSiteAction::class)->execute($site);

    $composerCalls = collect($execCalls)->filter(fn ($c) => str_contains($c, 'composer install'));
    expect($composerCalls)->toBeEmpty();
});

it('laravel provisioner runs artisan key:generate during environment setup', function () {
    $server = Server::factory()->create(['status' => ServerStatus::Active]);
    $site = Site::factory()->forServer($server)->pending()->create([
        'repository' => null,
        'source_control_account_id' => null,
        'project_type' => ProjectType::Laravel,
    ]);

    $execCalls = [];
    $mockConnection = mockSshConnection($execCalls);
    $this->app->instance(SshService::class, mockSshService($mockConnection));

    app(ProvisionSiteAction::class)->execute($site);

    $keyGenCalls = collect($execCalls)->filter(fn ($c) => str_contains($c, 'key:generate'));
    expect($keyGenCalls)->not->toBeEmpty();
});

it('laravel provisioner sets correct permissions with sudo', function () {
    $server = Server::factory()->create(['status' => ServerStatus::Active]);
    $site = Site::factory()->forServer($server)->pending()->create([
        'repository' => null,
        'source_control_account_id' => null,
        'project_type' => ProjectType::Laravel,
    ]);

    $execCalls = [];
    $mockConnection = mockSshConnection($execCalls);
    $this->app->instance(SshService::class, mockSshService($mockConnection));

    app(ProvisionSiteAction::class)->execute($site);

    $chownCalls = collect($execCalls)->filter(fn ($c) => str_contains($c, 'sudo chown -R'));
    expect($chownCalls)->not->toBeEmpty();

    $storageCalls = collect($execCalls)->filter(fn ($c) => str_contains($c, 'storage') && str_contains($c, '775'));
    expect($storageCalls)->not->toBeEmpty();

    $envCalls = collect($execCalls)->filter(fn ($c) => str_contains($c, '.env') && str_contains($c, '640'));
    expect($envCalls)->not->toBeEmpty();
});

it('static html provisioner does not run composer or npm', function () {
    $server = Server::factory()->create(['status' => ServerStatus::Active]);
    $site = Site::factory()->forServer($server)->pending()->create([
        'repository' => null,
        'source_control_account_id' => null,
        'project_type' => ProjectType::StaticHtml,
        'directory' => '/',
    ]);

    $execCalls = [];
    $mockConnection = mockSshConnection($execCalls);
    $this->app->instance(SshService::class, mockSshService($mockConnection));

    app(ProvisionSiteAction::class)->execute($site);

    $composerCalls = collect($execCalls)->filter(fn ($c) => str_contains($c, 'composer'));
    $npmCalls = collect($execCalls)->filter(fn ($c) => str_contains($c, 'npm'));
    expect($composerCalls)->toBeEmpty();
    expect($npmCalls)->toBeEmpty();
});

it('wordpress provisioner sets wp-content permissions', function () {
    $server = Server::factory()->create(['status' => ServerStatus::Active]);
    $site = Site::factory()->forServer($server)->pending()->create([
        'repository' => null,
        'source_control_account_id' => null,
        'project_type' => ProjectType::WordPress,
        'directory' => '/',
    ]);

    $execCalls = [];
    $mockConnection = mockSshConnection($execCalls);
    $this->app->instance(SshService::class, mockSshService($mockConnection));

    app(ProvisionSiteAction::class)->execute($site);

    $wpCalls = collect($execCalls)->filter(fn ($c) => str_contains($c, 'wp-content'));
    expect($wpCalls)->not->toBeEmpty();
});

it('symfony provisioner creates var/cache and var/log directories', function () {
    $server = Server::factory()->create(['status' => ServerStatus::Active]);
    $site = Site::factory()->forServer($server)->pending()->create([
        'repository' => null,
        'source_control_account_id' => null,
        'project_type' => ProjectType::Symfony,
        'directory' => '/public',
    ]);

    $execCalls = [];
    $mockConnection = mockSshConnection($execCalls);
    $this->app->instance(SshService::class, mockSshService($mockConnection));

    app(ProvisionSiteAction::class)->execute($site);

    $varCalls = collect($execCalls)->filter(fn ($c) => str_contains($c, 'var/cache') && str_contains($c, 'var/log'));
    expect($varCalls)->not->toBeEmpty();
});
