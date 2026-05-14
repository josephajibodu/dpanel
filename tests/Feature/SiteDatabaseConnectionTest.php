<?php

use App\Actions\Sites\ProvisionSiteAction;
use App\Enums\ProjectType;
use App\Enums\ServerStatus;
use App\Events\ServerSitesUpdated;
use App\Jobs\CreateSiteJob;
use App\Models\DatabaseUser;
use App\Models\Server;
use App\Models\ServerDatabase;
use App\Models\Site;
use App\Models\Team;
use App\Models\User;
use App\Services\Ssh\SshConnection;
use App\Services\Ssh\SshService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = Team::factory()->forUser($this->user)->create();
    $this->user->switchTeam($this->team);
    $this->server = Server::factory()->forTeam($this->team)->create([
        'status' => ServerStatus::Active,
        'database_type' => 'mysql',
    ]);
});

// --------------------------------------------------------------------------
// Validation
// --------------------------------------------------------------------------

describe('StoreSiteRequest validation', function () {
    it('accepts a valid server_database_id belonging to the same server', function () {
        Queue::fake();

        $database = ServerDatabase::factory()->create([
            'server_id' => $this->server->id,
            'status' => 'ready',
        ]);

        $response = $this->actingAs($this->user)
            ->post("/servers/{$this->server->id}/sites", [
                'server_id' => $this->server->id,
                'domain' => 'example.com',
                'directory' => '/public',
                'project_type' => 'laravel',
                'php_version' => '8.3',
                'branch' => 'main',
                'repository_provider' => 'github',
                'server_database_id' => $database->id,
            ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $this->assertDatabaseHas('sites', [
            'server_id' => $this->server->id,
            'domain' => 'example.com',
            'server_database_id' => $database->id,
        ]);

        Queue::assertPushed(CreateSiteJob::class);
    });

    it('rejects a server_database_id belonging to a different server', function () {
        $otherServer = Server::factory()->forTeam($this->team)->create([
            'status' => ServerStatus::Active,
        ]);

        $database = ServerDatabase::factory()->create([
            'server_id' => $otherServer->id,
            'status' => 'ready',
        ]);

        $response = $this->actingAs($this->user)
            ->post("/servers/{$this->server->id}/sites", [
                'server_id' => $this->server->id,
                'domain' => 'example.com',
                'directory' => '/public',
                'project_type' => 'laravel',
                'php_version' => '8.3',
                'branch' => 'main',
                'repository_provider' => 'github',
                'server_database_id' => $database->id,
            ]);

        $response->assertSessionHasErrors('server_database_id');
    });

    it('allows null server_database_id', function () {
        Queue::fake();

        $response = $this->actingAs($this->user)
            ->post("/servers/{$this->server->id}/sites", [
                'server_id' => $this->server->id,
                'domain' => 'no-db.com',
                'directory' => '/public',
                'project_type' => 'laravel',
                'php_version' => '8.3',
                'branch' => 'main',
                'repository_provider' => 'github',
                'server_database_id' => null,
            ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('sites', [
            'domain' => 'no-db.com',
            'server_database_id' => null,
        ]);
    });
});

// --------------------------------------------------------------------------
// CreateSiteAction persistence
// --------------------------------------------------------------------------

describe('CreateSiteAction', function () {
    it('persists server_database_id from SiteData', function () {
        Queue::fake();

        $database = ServerDatabase::factory()->create([
            'server_id' => $this->server->id,
            'status' => 'ready',
        ]);

        $this->actingAs($this->user)
            ->post("/servers/{$this->server->id}/sites", [
                'server_id' => $this->server->id,
                'domain' => 'with-db.com',
                'directory' => '/public',
                'project_type' => 'laravel',
                'php_version' => '8.3',
                'branch' => 'main',
                'repository_provider' => 'github',
                'server_database_id' => $database->id,
            ]);

        $site = Site::where('domain', 'with-db.com')->firstOrFail();
        expect($site->server_database_id)->toBe($database->id);
        expect($site->serverDatabase->name)->toBe($database->name);
    });
});

// --------------------------------------------------------------------------
// Create page passes databases prop
// --------------------------------------------------------------------------

describe('SiteController::create', function () {
    it('passes databases as an Inertia prop', function () {
        ServerDatabase::factory()->count(2)->create([
            'server_id' => $this->server->id,
        ]);

        $response = $this->actingAs($this->user)
            ->get("/servers/{$this->server->id}/sites/create");

        $response->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('sites/create')
                ->has('databases.data', 2)
            );
    });
});

// --------------------------------------------------------------------------
// Provisioner env injection
// --------------------------------------------------------------------------

describe('LaravelSiteProvisioner env injection', function () {
    it('injects APP_* and DB_* env values when a database is linked', function () {
        Event::fake([ServerSitesUpdated::class]);

        $database = ServerDatabase::factory()->create([
            'server_id' => $this->server->id,
            'name' => 'myapp_db',
            'status' => 'ready',
        ]);

        $dbUser = DatabaseUser::factory()->create([
            'server_id' => $this->server->id,
            'username' => 'artisan',
            'password' => 'secret_password',
            'databases' => ['myapp_db'],
        ]);

        $site = Site::factory()->forServer($this->server)->pending()->create([
            'repository' => null,
            'source_control_account_id' => null,
            'project_type' => ProjectType::Laravel,
            'server_database_id' => $database->id,
            'domain' => 'myapp.example.com',
        ]);
        $site->deployScript()->create(['script' => 'echo "test"']);

        $execCalls = [];
        $mockConnection = \Mockery::mock(SshConnection::class)->makePartial();
        $mockConnection->shouldReceive('exec')
            ->andReturnUsing(function (string $cmd) use (&$execCalls) {
                $execCalls[] = $cmd;

                return str_contains($cmd, 'nginx -t') ? 'syntax is ok' : '';
            });
        $mockConnection->shouldReceive('disconnect')->andReturn(null);

        $sshService = \Mockery::mock(SshService::class);
        $sshService->shouldReceive('connect')->once()->andReturn($mockConnection);
        $this->app->instance(SshService::class, $sshService);

        app(ProvisionSiteAction::class)->execute($site);

        $sedCalls = collect($execCalls)->filter(fn ($c) => str_contains($c, 'sed'));

        expect($sedCalls->contains(fn ($c) => str_contains($c, 'APP_ENV=production')))->toBeTrue();
        expect($sedCalls->contains(fn ($c) => str_contains($c, 'APP_DEBUG=false')))->toBeTrue();
        expect($sedCalls->contains(fn ($c) => str_contains($c, 'APP_URL=https://myapp.example.com')))->toBeTrue();
        expect($sedCalls->contains(fn ($c) => str_contains($c, 'DB_CONNECTION=mysql')))->toBeTrue();
        expect($sedCalls->contains(fn ($c) => str_contains($c, 'DB_HOST=127.0.0.1')))->toBeTrue();
        expect($sedCalls->contains(fn ($c) => str_contains($c, 'DB_PORT=3306')))->toBeTrue();
        expect($sedCalls->contains(fn ($c) => str_contains($c, 'DB_DATABASE=myapp_db')))->toBeTrue();
        expect($sedCalls->contains(fn ($c) => str_contains($c, 'DB_USERNAME=artisan')))->toBeTrue();
        expect($sedCalls->contains(fn ($c) => str_contains($c, 'DB_PASSWORD=')))->toBeTrue();
    });

    it('injects pgsql connection when server uses postgresql', function () {
        Event::fake([ServerSitesUpdated::class]);

        $this->server->update(['database_type' => 'postgresql']);

        $database = ServerDatabase::factory()->create([
            'server_id' => $this->server->id,
            'name' => 'pg_db',
            'status' => 'ready',
        ]);

        DatabaseUser::factory()->create([
            'server_id' => $this->server->id,
            'username' => 'artisan',
            'password' => 'pgpass',
            'databases' => ['pg_db'],
        ]);

        $site = Site::factory()->forServer($this->server)->pending()->create([
            'repository' => null,
            'source_control_account_id' => null,
            'project_type' => ProjectType::Laravel,
            'server_database_id' => $database->id,
            'domain' => 'pg.example.com',
        ]);
        $site->deployScript()->create(['script' => 'echo "test"']);

        $execCalls = [];
        $mockConnection = \Mockery::mock(SshConnection::class)->makePartial();
        $mockConnection->shouldReceive('exec')
            ->andReturnUsing(function (string $cmd) use (&$execCalls) {
                $execCalls[] = $cmd;

                return str_contains($cmd, 'nginx -t') ? 'syntax is ok' : '';
            });
        $mockConnection->shouldReceive('disconnect')->andReturn(null);

        $sshService = \Mockery::mock(SshService::class);
        $sshService->shouldReceive('connect')->once()->andReturn($mockConnection);
        $this->app->instance(SshService::class, $sshService);

        app(ProvisionSiteAction::class)->execute($site);

        $sedCalls = collect($execCalls)->filter(fn ($c) => str_contains($c, 'sed'));

        expect($sedCalls->contains(fn ($c) => str_contains($c, 'DB_CONNECTION=pgsql')))->toBeTrue();
        expect($sedCalls->contains(fn ($c) => str_contains($c, 'DB_PORT=5432')))->toBeTrue();
    });

    it('only injects APP_* values when no database is linked', function () {
        Event::fake([ServerSitesUpdated::class]);

        $site = Site::factory()->forServer($this->server)->pending()->create([
            'repository' => null,
            'source_control_account_id' => null,
            'project_type' => ProjectType::Laravel,
            'server_database_id' => null,
            'domain' => 'nodb.example.com',
        ]);
        $site->deployScript()->create(['script' => 'echo "test"']);

        $execCalls = [];
        $mockConnection = \Mockery::mock(SshConnection::class)->makePartial();
        $mockConnection->shouldReceive('exec')
            ->andReturnUsing(function (string $cmd) use (&$execCalls) {
                $execCalls[] = $cmd;

                return str_contains($cmd, 'nginx -t') ? 'syntax is ok' : '';
            });
        $mockConnection->shouldReceive('disconnect')->andReturn(null);

        $sshService = \Mockery::mock(SshService::class);
        $sshService->shouldReceive('connect')->once()->andReturn($mockConnection);
        $this->app->instance(SshService::class, $sshService);

        app(ProvisionSiteAction::class)->execute($site);

        $sedCalls = collect($execCalls)->filter(fn ($c) => str_contains($c, 'sed'));

        expect($sedCalls->contains(fn ($c) => str_contains($c, 'APP_ENV=production')))->toBeTrue();
        expect($sedCalls->contains(fn ($c) => str_contains($c, 'APP_DEBUG=false')))->toBeTrue();
        expect($sedCalls->contains(fn ($c) => str_contains($c, 'APP_URL=https://nodb.example.com')))->toBeTrue();

        expect($sedCalls->contains(fn ($c) => str_contains($c, 'DB_CONNECTION')))->toBeFalse();
        expect($sedCalls->contains(fn ($c) => str_contains($c, 'DB_DATABASE')))->toBeFalse();
        expect($sedCalls->contains(fn ($c) => str_contains($c, 'DB_USERNAME')))->toBeFalse();
    });

    it('correctly escapes URLs with forward slashes in sed commands', function () {
        Event::fake([ServerSitesUpdated::class]);

        $site = Site::factory()->forServer($this->server)->pending()->create([
            'repository' => null,
            'source_control_account_id' => null,
            'project_type' => ProjectType::Laravel,
            'server_database_id' => null,
            'domain' => 'app.flitops.xyz',
        ]);
        $site->deployScript()->create(['script' => 'echo "test"']);

        $execCalls = [];
        $mockConnection = \Mockery::mock(SshConnection::class)->makePartial();
        $mockConnection->shouldReceive('exec')
            ->andReturnUsing(function (string $cmd) use (&$execCalls) {
                $execCalls[] = $cmd;

                return str_contains($cmd, 'nginx -t') ? 'syntax is ok' : '';
            });
        $mockConnection->shouldReceive('disconnect')->andReturn(null);

        $sshService = \Mockery::mock(SshService::class);
        $sshService->shouldReceive('connect')->once()->andReturn($mockConnection);
        $this->app->instance(SshService::class, $sshService);

        app(ProvisionSiteAction::class)->execute($site);

        $sedCalls = collect($execCalls)->filter(fn ($c) => str_contains($c, 'sed'));

        $appUrlCall = $sedCalls->first(fn ($c) => str_contains($c, 'APP_URL'));
        expect($appUrlCall)->not->toBeNull();
        expect($appUrlCall)->toContain('APP_URL=https://app.flitops.xyz');
        expect($appUrlCall)->not->toContain('\\/');
    });
});
