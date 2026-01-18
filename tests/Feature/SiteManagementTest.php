<?php

use App\Enums\RepositoryProvider;
use App\Enums\ServerStatus;
use App\Jobs\CreateSiteJob;
use App\Jobs\DeleteSiteJob;
use App\Jobs\SyncEnvironmentJob;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->server = Server::factory()->create([
        'user_id' => $this->user->id,
        'status' => ServerStatus::Active,
    ]);
});

describe('site creation', function () {
    it('can view the site creation form', function () {
        $response = $this->actingAs($this->user)
            ->get("/servers/{$this->server->id}/sites/create");

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
            ->post("/servers/{$this->server->id}/sites", [
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
            ->post("/servers/{$this->server->id}/sites", [
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

    it('creates a default deploy script for laravel projects', function () {
        Queue::fake();

        $this->actingAs($this->user)
            ->post("/servers/{$this->server->id}/sites", [
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
            ->post("/servers/{$this->server->id}/sites", [
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

    it('validates repository format', function () {
        $response = $this->actingAs($this->user)
            ->post("/servers/{$this->server->id}/sites", [
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
        $otherServer = Server::factory()->create([
            'user_id' => $otherUser->id,
        ]);

        // The form request validation checks that the server belongs to the user
        // so we expect a validation error rather than a 403
        $response = $this->actingAs($this->user)
            ->post("/servers/{$otherServer->id}/sites", [
                'server_id' => $otherServer->id,
                'domain' => 'example.com',
                'directory' => '/public',
                'project_type' => 'laravel',
                'php_version' => '8.3',
                'branch' => 'main',
                'repository_provider' => 'github',
            ]);

        $response->assertSessionHasErrors('server_id');
    });
});

describe('site viewing', function () {
    it('can view a site', function () {
        $site = Site::factory()->create([
            'server_id' => $this->server->id,
        ]);

        $response = $this->actingAs($this->user)
            ->get("/sites/{$site->id}");

        $response->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('sites/show')
                ->has('site.data')
                ->where('site.data.domain', $site->domain)
            );
    });

    it('cannot view other users sites', function () {
        $otherUser = User::factory()->create();
        $otherServer = Server::factory()->create([
            'user_id' => $otherUser->id,
        ]);
        $site = Site::factory()->create([
            'server_id' => $otherServer->id,
        ]);

        $response = $this->actingAs($this->user)
            ->get("/sites/{$site->id}");

        $response->assertForbidden();
    });
});

describe('site deletion', function () {
    it('can delete a site', function () {
        Queue::fake();

        $site = Site::factory()->create([
            'server_id' => $this->server->id,
        ]);

        $response = $this->actingAs($this->user)
            ->delete("/sites/{$site->id}");

        $response->assertRedirect("/servers/{$this->server->id}");

        Queue::assertPushed(DeleteSiteJob::class);
    });

    it('cannot delete other users sites', function () {
        $otherUser = User::factory()->create();
        $otherServer = Server::factory()->create([
            'user_id' => $otherUser->id,
        ]);
        $site = Site::factory()->create([
            'server_id' => $otherServer->id,
        ]);

        $response = $this->actingAs($this->user)
            ->delete("/sites/{$site->id}");

        $response->assertForbidden();
    });
});

describe('environment variables', function () {
    it('can update environment variables', function () {
        Queue::fake();

        $site = Site::factory()->create([
            'server_id' => $this->server->id,
        ]);

        $response = $this->actingAs($this->user)
            ->put("/sites/{$site->id}/environment", [
                'variables' => [
                    ['key' => 'APP_KEY', 'value' => 'base64:test'],
                    ['key' => 'DB_HOST', 'value' => 'localhost'],
                ],
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

    it('validates environment variable keys', function () {
        $site = Site::factory()->create([
            'server_id' => $this->server->id,
        ]);

        $response = $this->actingAs($this->user)
            ->put("/sites/{$site->id}/environment", [
                'variables' => [
                    ['key' => '123INVALID', 'value' => 'test'],
                ],
            ]);

        $response->assertSessionHasErrors('variables.0.key');
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
            ->put("/sites/{$site->id}/deploy-script", [
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
            ->put("/sites/{$site->id}/deploy-script", [
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
