<?php

use App\Enums\RepositoryProvider;
use App\Enums\ServerStatus;
use App\Enums\SiteStatus;
use App\Events\ServerSitesUpdated;
use App\Jobs\CreateSiteJob;
use App\Jobs\DeleteSiteJob;
use App\Jobs\DeploySiteJob;
use App\Jobs\SyncEnvironmentJob;
use App\Models\Server;
use App\Models\Site;
use App\Models\Team;
use App\Models\User;
use App\Services\Cloudflare\CloudflareDnsService;
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
    ]);
});

describe('site creation', function () {
    it('can view the site creation form', function () {
        $response = $this->actingAs($this->user)
            ->get("/{$this->team->slug}/servers/{$this->server->id}/sites/create");

        $response->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('sites/create')
                ->has('server.data')
                ->has('projectTypes')
                ->has('repositoryProviders')
                ->has('phpVersions')
            );
    });

    it('can create a site with minimal data', function () {
        Queue::fake();

        $response = $this->actingAs($this->user)
            ->post("/{$this->team->slug}/servers/{$this->server->id}/sites", [
                'server_id' => $this->server->id,
                'domain' => 'example.com',
                'directory' => '/public',
                'project_type' => 'laravel',
                'php_version' => '8.3',
                'branch' => 'main',
                'repository_provider' => 'github',
            ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('sites', [
            'server_id' => $this->server->id,
            'domain' => 'example.com',
            'directory' => '/public',
            'project_type' => 'laravel',
            'php_version' => '8.3',
            'status' => 'pending',
        ]);

        Queue::assertPushed(CreateSiteJob::class);
    });

    it('can create a site with repository', function () {
        Queue::fake();

        $response = $this->actingAs($this->user)
            ->post("/{$this->team->slug}/servers/{$this->server->id}/sites", [
                'server_id' => $this->server->id,
                'domain' => 'myapp.com',
                'directory' => '/public',
                'repository' => 'username/repo',
                'repository_provider' => 'github',
                'branch' => 'main',
                'project_type' => 'laravel',
                'php_version' => '8.4',
                'auto_deploy' => true,
            ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('sites', [
            'server_id' => $this->server->id,
            'domain' => 'myapp.com',
            'repository' => 'username/repo',
            'repository_provider' => 'github',
            'branch' => 'main',
            'auto_deploy' => true,
        ]);
    });

    it('does not trigger deployment when a site is created', function () {
        Queue::fake();

        $response = $this->actingAs($this->user)
            ->post("/{$this->team->slug}/servers/{$this->server->id}/sites", [
                'server_id' => $this->server->id,
                'domain' => 'manual-deploy.com',
                'directory' => '/public',
                'project_type' => 'laravel',
                'php_version' => '8.3',
                'branch' => 'main',
                'repository_provider' => 'github',
            ]);

        $response->assertRedirect();

        $site = Site::where('domain', 'manual-deploy.com')->firstOrFail();

        $this->assertDatabaseHas('sites', [
            'id' => $site->id,
            'status' => 'pending',
        ]);
        expect($site->deployments()->count())->toBe(0);

        Queue::assertPushed(CreateSiteJob::class);
        Queue::assertNotPushed(DeploySiteJob::class);
    });

    it('creates a default deploy script for laravel projects', function () {
        Queue::fake();

        $this->actingAs($this->user)
            ->post("/{$this->team->slug}/servers/{$this->server->id}/sites", [
                'server_id' => $this->server->id,
                'domain' => 'laravel.com',
                'directory' => '/public',
                'project_type' => 'laravel',
                'php_version' => '8.3',
                'branch' => 'main',
                'repository_provider' => 'github',
            ]);

        $site = Site::where('domain', 'laravel.com')->first();

        expect($site)->not->toBeNull();
        expect($site->deployScript)->not->toBeNull();
        expect($site->deployScript->script)->toContain('artisan migrate');
    });

    it('validates domain format', function () {
        $response = $this->actingAs($this->user)
            ->post("/{$this->team->slug}/servers/{$this->server->id}/sites", [
                'server_id' => $this->server->id,
                'domain' => 'invalid domain!',
                'directory' => '/public',
                'project_type' => 'laravel',
                'php_version' => '8.3',
                'branch' => 'main',
                'repository_provider' => 'github',
            ]);

        $response->assertSessionHasErrors('domain');
    });

    it('can create a site with site_name instead of domain', function () {
        Queue::fake();

        $mock = \Mockery::mock(CloudflareDnsService::class);
        $mock->shouldReceive('createARecord')
            ->once()
            ->with('react-blog.flitops.xyz', '146.190.253.93')
            ->andReturn('cf-record-123');
        $this->app->instance(CloudflareDnsService::class, $mock);

        $this->server->update(['ip_address' => '146.190.253.93']);

        $response = $this->actingAs($this->user)
            ->post("/{$this->team->slug}/servers/{$this->server->id}/sites", [
                'server_id' => $this->server->id,
                'site_name' => 'react-blog',
                'directory' => '/public',
                'project_type' => 'laravel',
                'php_version' => '8.3',
                'branch' => 'main',
                'repository_provider' => 'github',
            ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('sites', [
            'server_id' => $this->server->id,
            'site_name' => 'react-blog',
            'domain' => 'react-blog.flitops.xyz',
            'root_path' => 'react-blog.flitops.xyz',
            'directory' => '/public',
            'project_type' => 'laravel',
            'php_version' => '8.3',
            'status' => 'pending',
        ]);

        $site = \App\Models\Site::query()->where('domain', 'react-blog.flitops.xyz')->firstOrFail();
        $this->assertDatabaseHas('site_domains', [
            'site_id' => $site->id,
            'hostname' => 'react-blog.flitops.xyz',
            'cloudflare_dns_record_id' => 'cf-record-123',
        ]);

        Queue::assertPushed(CreateSiteJob::class);
    });

    it('validates site_name format', function () {
        $response = $this->actingAs($this->user)
            ->post("/{$this->team->slug}/servers/{$this->server->id}/sites", [
                'server_id' => $this->server->id,
                'site_name' => 'invalid site name!',
                'directory' => '/public',
                'project_type' => 'laravel',
                'php_version' => '8.3',
                'branch' => 'main',
                'repository_provider' => 'github',
            ]);

        $response->assertSessionHasErrors('site_name');
    });

    it('requires either domain or site_name', function () {
        $response = $this->actingAs($this->user)
            ->post("/{$this->team->slug}/servers/{$this->server->id}/sites", [
                'server_id' => $this->server->id,
                'directory' => '/public',
                'project_type' => 'laravel',
                'php_version' => '8.3',
                'branch' => 'main',
                'repository_provider' => 'github',
            ]);

        $response->assertSessionHasErrors(['domain', 'site_name']);
    });

    it('validates repository format', function () {
        $response = $this->actingAs($this->user)
            ->post("/{$this->team->slug}/servers/{$this->server->id}/sites", [
                'server_id' => $this->server->id,
                'domain' => 'example.com',
                'directory' => '/public',
                'repository' => 'invalid-repo-format',
                'repository_provider' => 'github',
                'project_type' => 'laravel',
                'php_version' => '8.3',
                'branch' => 'main',
            ]);

        $response->assertSessionHasErrors('repository');
    });

    it('prevents creating sites on other users servers', function () {
        $otherUser = User::factory()->create();
        $otherTeam = Team::factory()->forUser($otherUser)->create();
        $otherServer = Server::factory()->forTeam($otherTeam)->create();

        $response = $this->actingAs($this->user)
            ->post("/{$otherTeam->slug}/servers/{$otherServer->id}/sites", [
                'server_id' => $otherServer->id,
                'domain' => 'example.com',
                'directory' => '/public',
                'project_type' => 'laravel',
                'php_version' => '8.3',
                'branch' => 'main',
                'repository_provider' => 'github',
            ]);

        $response->assertForbidden();
    });
});

describe('sites index (under server)', function () {
    it('can view the sites index for a server', function () {
        $site = Site::factory()->create([
            'server_id' => $this->server->id,
        ]);

        $response = $this->actingAs($this->user)
            ->get("/{$this->team->slug}/servers/{$this->server->id}/sites");

        $response->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('servers/sites/index')
                ->has('server.data')
                ->has('sites.data')
                ->where('sites.data.0.domain', $site->domain)
            );
    });

    it('cannot view sites index for other users server', function () {
        $otherUser = User::factory()->create();
        $otherTeam = Team::factory()->forUser($otherUser)->create();
        $otherServer = Server::factory()->forTeam($otherTeam)->create();

        $response = $this->actingAs($this->user)
            ->get("/{$otherTeam->slug}/servers/{$otherServer->id}/sites");

        $response->assertForbidden();
    });
});

describe('site viewing', function () {
    it('can view a site', function () {
        $site = Site::factory()->create([
            'server_id' => $this->server->id,
        ]);

        $response = $this->actingAs($this->user)
            ->get("/{$this->team->slug}/servers/{$this->server->id}/sites/{$site->id}");

        $response->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('sites/show')
                ->has('server.data')
                ->has('site.data')
                ->where('site.data.domain', $site->domain)
            );
    });

    it('cannot view other users sites', function () {
        $otherUser = User::factory()->create();
        $otherTeam = Team::factory()->forUser($otherUser)->create();
        $otherServer = Server::factory()->forTeam($otherTeam)->create();
        $site = Site::factory()->create([
            'server_id' => $otherServer->id,
        ]);

        $response = $this->actingAs($this->user)
            ->get("/{$otherTeam->slug}/servers/{$otherServer->id}/sites/{$site->id}");

        $response->assertForbidden();
    });
});

describe('site deletion', function () {
    it('can delete a site', function () {
        Queue::fake();
        Event::fake([ServerSitesUpdated::class]);

        $site = Site::factory()->create([
            'server_id' => $this->server->id,
        ]);

        $response = $this->actingAs($this->user)
            ->delete("/{$this->team->slug}/servers/{$this->server->id}/sites/{$site->id}");

        $response->assertRedirect("/{$this->team->slug}/servers/{$this->server->id}/sites");

        expect($site->fresh()->status)->toBe(SiteStatus::Deleting);
        Queue::assertPushed(DeleteSiteJob::class);
        Event::assertDispatched(ServerSitesUpdated::class);
    });

    it('cannot delete other users sites', function () {
        $otherUser = User::factory()->create();
        $otherTeam = Team::factory()->forUser($otherUser)->create();
        $otherServer = Server::factory()->forTeam($otherTeam)->create();
        $site = Site::factory()->create([
            'server_id' => $otherServer->id,
        ]);

        $response = $this->actingAs($this->user)
            ->delete("/{$otherTeam->slug}/servers/{$otherServer->id}/sites/{$site->id}");

        $response->assertForbidden();
    });
});

describe('site sub-pages', function () {
    it('can view site deployments index', function () {
        $site = Site::factory()->create([
            'server_id' => $this->server->id,
        ]);

        $response = $this->actingAs($this->user)
            ->get("/{$this->team->slug}/servers/{$this->server->id}/sites/{$site->id}/deployments");

        $response->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('sites/deployments/index')
                ->has('server.data')
                ->has('site.data')
                ->has('deployments.data')
            );
    });

    it('can view site environment page', function () {
        $site = Site::factory()->create([
            'server_id' => $this->server->id,
        ]);

        $sshConnectionMock = \Mockery::mock(SshConnection::class);
        $sshConnectionMock->shouldReceive('download')
            ->once()
            ->andReturn("APP_NAME=FlitOps\nAPP_ENV=production");

        $sshServiceMock = \Mockery::mock(SshService::class);
        $sshServiceMock->shouldReceive('connect')
            ->once()
            ->withArgs(fn ($server) => $server->is($this->server))
            ->andReturn($sshConnectionMock);
        $this->app->instance(SshService::class, $sshServiceMock);

        $response = $this->actingAs($this->user)
            ->get("/{$this->team->slug}/servers/{$this->server->id}/sites/{$site->id}/environment");

        $response->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('sites/environment/show')
                ->has('server.data')
                ->has('site.data')
                ->where('env_content', "APP_NAME=FlitOps\nAPP_ENV=production")
            );
    });

    it('can view site deploy script page', function () {
        $site = Site::factory()->create([
            'server_id' => $this->server->id,
        ]);

        $response = $this->actingAs($this->user)
            ->get("/{$this->team->slug}/servers/{$this->server->id}/sites/{$site->id}/deploy-script");

        $response->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('sites/deploy-script/show')
                ->has('server.data')
                ->has('site.data')
            );
    });
});

describe('deployment triggering', function () {
    it('can trigger deployment manually from deployments endpoint', function () {
        Queue::fake();

        $site = Site::factory()->create([
            'server_id' => $this->server->id,
        ]);

        $response = $this->actingAs($this->user)
            ->post("/{$this->team->slug}/servers/{$this->server->id}/sites/{$site->id}/deployments");

        $response->assertRedirect();

        $this->assertDatabaseHas('deployments', [
            'site_id' => $site->id,
            'user_id' => $this->user->id,
            'status' => 'pending',
            'triggered_by' => 'manual',
        ]);

        Queue::assertPushed(DeploySiteJob::class);
    });
});

describe('environment variables', function () {
    it('can update environment variables from env content', function () {
        Queue::fake();

        $site = Site::factory()->create([
            'server_id' => $this->server->id,
        ]);

        $response = $this->actingAs($this->user)
            ->put("/{$this->team->slug}/servers/{$site->server_id}/sites/{$site->id}/environment", [
                'env_content' => "APP_KEY=base64:test\nDB_HOST=localhost",
            ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('environment_variables', [
            'site_id' => $site->id,
            'key' => 'APP_KEY',
        ]);
        $this->assertDatabaseHas('environment_variables', [
            'site_id' => $site->id,
            'key' => 'DB_HOST',
        ]);

        Queue::assertPushed(SyncEnvironmentJob::class);
    });

    it('ignores comments and blank lines in env content', function () {
        Queue::fake();

        $site = Site::factory()->create([
            'server_id' => $this->server->id,
        ]);

        $response = $this->actingAs($this->user)
            ->put("/{$this->team->slug}/servers/{$site->server_id}/sites/{$site->id}/environment", [
                'env_content' => "# App settings\n\nAPP_ENV=production\n\n# Database\nDB_HOST=127.0.0.1\n",
            ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('environment_variables', [
            'site_id' => $site->id,
            'key' => 'APP_ENV',
        ]);
        $this->assertDatabaseHas('environment_variables', [
            'site_id' => $site->id,
            'key' => 'DB_HOST',
        ]);
        $this->assertDatabaseCount('environment_variables', 2);
    });

    it('validates malformed env content lines', function () {
        $site = Site::factory()->create([
            'server_id' => $this->server->id,
        ]);

        $response = $this->actingAs($this->user)
            ->put("/{$this->team->slug}/servers/{$site->server_id}/sites/{$site->id}/environment", [
                'env_content' => "APP_NAME=FlitOps\nINVALID_LINE",
            ]);

        $response->assertSessionHasErrors('env_content');
    });

    it('validates environment variable key format in env content', function () {
        $site = Site::factory()->create([
            'server_id' => $this->server->id,
        ]);

        $response = $this->actingAs($this->user)
            ->put("/{$this->team->slug}/servers/{$site->server_id}/sites/{$site->id}/environment", [
                'env_content' => '123INVALID=test',
            ]);

        $response->assertSessionHasErrors('env_content');
    });
});

describe('deploy script', function () {
    it('can update deploy script', function () {
        $site = Site::factory()->create([
            'server_id' => $this->server->id,
        ]);
        $site->deployScript()->create(['script' => 'echo "test"']);

        $newScript = 'cd $SITE_ROOT && git pull';

        $response = $this->actingAs($this->user)
            ->put("/{$this->team->slug}/servers/{$site->server_id}/sites/{$site->id}/deploy-script", [
                'script' => $newScript,
            ]);

        $response->assertRedirect();

        $site->refresh();
        expect($site->deployScript->script)->toBe($newScript);
    });

    it('validates deploy script is required', function () {
        $site = Site::factory()->create([
            'server_id' => $this->server->id,
        ]);

        $response = $this->actingAs($this->user)
            ->put("/{$this->team->slug}/servers/{$site->server_id}/sites/{$site->id}/deploy-script", [
                'script' => '',
            ]);

        $response->assertSessionHasErrors('script');
    });
});

describe('site model', function () {
    it('generates correct root path', function () {
        $site = Site::factory()->create([
            'domain' => 'test.example.com',
        ]);

        expect($site->rootPath())->toBe('/home/artisan/test.example.com');
    });

    it('generates correct web root with directory', function () {
        $site = Site::factory()->create([
            'domain' => 'test.example.com',
            'directory' => '/public',
        ]);

        expect($site->webRoot())->toBe('/home/artisan/test.example.com/public');
    });

    it('generates correct repository URL', function () {
        $site = Site::factory()->create([
            'repository' => 'user/repo',
            'repository_provider' => RepositoryProvider::Github,
        ]);

        expect($site->repositoryUrl())->toBe('https://github.com/user/repo');
    });
});
