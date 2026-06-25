<?php

use App\Enums\ServerStatus;
use App\Jobs\SyncEnvironmentJob;
use App\Models\Server;
use App\Models\Site;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = Team::factory()->forUser($this->user)->create();
    $this->server = Server::factory()->forTeam($this->team)->create([
        'status' => ServerStatus::Active,
    ]);
    $this->site = Site::factory()->create([
        'server_id' => $this->server->id,
        'domain' => 'example.com',
    ]);
});

describe('show', function () {
    it('renders the environment page', function () {
        $response = $this->actingAs($this->user)
            ->get("/{$this->team->slug}/servers/{$this->server->id}/sites/{$this->site->id}/environment");

        $response->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('sites/environment/show')
                ->has('server.data')
                ->has('site.data')
                ->has('has_workers')
            );
    });

    it('requires authentication', function () {
        $response = $this->get("/{$this->team->slug}/servers/{$this->server->id}/sites/{$this->site->id}/environment");

        $response->assertRedirect('/login');
    });

    it('returns 404 for a site belonging to another team', function () {
        $otherUser = User::factory()->create();
        $otherTeam = Team::factory()->forUser($otherUser)->create();
        $otherServer = Server::factory()->forTeam($otherTeam)->create();
        $otherSite = Site::factory()->create(['server_id' => $otherServer->id]);

        $response = $this->actingAs($this->user)
            ->get("/{$this->team->slug}/servers/{$otherServer->id}/sites/{$otherSite->id}/environment");

        $response->assertNotFound();
    });
});

describe('update', function () {
    it('stores env_content and dispatches SyncEnvironmentJob', function () {
        Queue::fake();

        $envContent = "APP_NAME=MyApp\nAPP_ENV=production\n\nDB_HOST=localhost";

        $response = $this->actingAs($this->user)
            ->put("/{$this->team->slug}/servers/{$this->server->id}/sites/{$this->site->id}/environment", [
                'env_content' => $envContent,
            ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('sites', [
            'id' => $this->site->id,
            'env_content' => $envContent,
        ]);

        Queue::assertPushed(SyncEnvironmentJob::class, fn ($job) => $job->site->id === $this->site->id);
    });

    it('preserves blank lines in env_content', function () {
        Queue::fake();

        $envContent = "APP_NAME=MyApp\n\nDB_HOST=localhost\n\n# Section comment\nCACHE_DRIVER=redis";

        $this->actingAs($this->user)
            ->put("/{$this->team->slug}/servers/{$this->server->id}/sites/{$this->site->id}/environment", [
                'env_content' => $envContent,
            ]);

        $this->assertDatabaseHas('sites', [
            'id' => $this->site->id,
            'env_content' => $envContent,
        ]);
    });

    it('also syncs individual environment variables', function () {
        Queue::fake();

        $this->actingAs($this->user)
            ->put("/{$this->team->slug}/servers/{$this->server->id}/sites/{$this->site->id}/environment", [
                'env_content' => "APP_NAME=MyApp\nDB_HOST=localhost",
            ]);

        // Values are encrypted in the DB — just verify keys were stored
        expect($this->site->environmentVariables()->pluck('key')->sort()->values()->all())
            ->toBe(['APP_NAME', 'DB_HOST']);
    });

    it('deletes removed environment variables', function () {
        Queue::fake();

        $this->site->environmentVariables()->create(['key' => 'OLD_KEY', 'value' => 'old_value']);

        $this->actingAs($this->user)
            ->put("/{$this->team->slug}/servers/{$this->server->id}/sites/{$this->site->id}/environment", [
                'env_content' => 'APP_NAME=MyApp',
            ]);

        $this->assertDatabaseMissing('environment_variables', [
            'site_id' => $this->site->id,
            'key' => 'OLD_KEY',
        ]);
    });

    it('dispatches SyncEnvironmentJob with clear_config_cache flag', function () {
        Queue::fake();

        $this->actingAs($this->user)
            ->put("/{$this->team->slug}/servers/{$this->server->id}/sites/{$this->site->id}/environment", [
                'env_content' => 'APP_NAME=MyApp',
                'clear_config_cache' => true,
            ]);

        Queue::assertPushed(SyncEnvironmentJob::class, fn ($job) => $job->clearConfigCache === true);
    });

    it('rejects invalid env key format', function () {
        $response = $this->actingAs($this->user)
            ->put("/{$this->team->slug}/servers/{$this->server->id}/sites/{$this->site->id}/environment", [
                'env_content' => '123INVALID=value',
            ]);

        $response->assertSessionHasErrors('env_content');
    });

    it('rejects lines without an equals sign', function () {
        $response = $this->actingAs($this->user)
            ->put("/{$this->team->slug}/servers/{$this->server->id}/sites/{$this->site->id}/environment", [
                'env_content' => 'BADLINE',
            ]);

        $response->assertSessionHasErrors('env_content');
    });

    it('requires authentication', function () {
        $response = $this->put("/{$this->team->slug}/servers/{$this->server->id}/sites/{$this->site->id}/environment", [
            'env_content' => 'APP_NAME=MyApp',
        ]);

        $response->assertRedirect('/login');
    });

    it('returns 404 for a site belonging to another team', function () {
        $otherUser = User::factory()->create();
        $otherTeam = Team::factory()->forUser($otherUser)->create();
        $otherServer = Server::factory()->forTeam($otherTeam)->create();
        $otherSite = Site::factory()->create(['server_id' => $otherServer->id]);

        $response = $this->actingAs($this->user)
            ->put("/{$this->team->slug}/servers/{$otherServer->id}/sites/{$otherSite->id}/environment", [
                'env_content' => 'APP_NAME=MyApp',
            ]);

        $response->assertNotFound();
    });
});
