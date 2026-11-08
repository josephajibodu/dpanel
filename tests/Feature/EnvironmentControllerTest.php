<?php

use App\Enums\ActivityEvent;
use App\Enums\ServerStatus;
use App\Jobs\SyncEnvironmentJob;
use App\Models\ActivityLog;
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

        // env_content is encrypted — verify via model decryption
        expect($this->site->fresh()->env_content)->toBe($envContent);

        Queue::assertPushed(SyncEnvironmentJob::class, fn ($job) => $job->site->id === $this->site->id);
    });

    it('preserves blank lines in env_content', function () {
        Queue::fake();

        $envContent = "APP_NAME=MyApp\n\nDB_HOST=localhost\n\n# Section comment\nCACHE_DRIVER=redis";

        $this->actingAs($this->user)
            ->put("/{$this->team->slug}/servers/{$this->server->id}/sites/{$this->site->id}/environment", [
                'env_content' => $envContent,
            ]);

        // env_content is encrypted — verify via model decryption
        expect($this->site->fresh()->env_content)->toBe($envContent);
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

describe('activity log', function () {
    it('records who saved and which keys were added, changed and removed, without values', function () {
        Queue::fake();
        $this->site->update(['env_content' => "APP_NAME=Old\nAPP_DEBUG=true\nKEEP=same"]);

        $this->actingAs($this->user)
            ->put("/{$this->team->slug}/servers/{$this->server->id}/sites/{$this->site->id}/environment", [
                'env_content' => "APP_NAME=New\nKEEP=same\nMAIL_HOST=smtp.example.com",
            ])
            ->assertRedirect();

        $entry = ActivityLog::sole();

        expect($entry->team_id)->toBe($this->team->id)
            ->and($entry->user_id)->toBe($this->user->id)
            ->and($entry->subject->is($this->site))->toBeTrue()
            ->and($entry->event)->toBe(ActivityEvent::SiteEnvironmentUpdated)
            ->and($entry->properties)->toBe([
                'first_save' => false,
                'added_keys' => ['MAIL_HOST'],
                'changed_keys' => ['APP_NAME'],
                'removed_keys' => ['APP_DEBUG'],
            ])
            ->and(json_encode($entry->properties))->not->toContain('smtp.example.com');
    });

    it('records nothing when the content did not change', function () {
        Queue::fake();
        $this->site->update(['env_content' => 'APP_NAME=Same']);

        $this->actingAs($this->user)
            ->put("/{$this->team->slug}/servers/{$this->server->id}/sites/{$this->site->id}/environment", [
                'env_content' => 'APP_NAME=Same',
            ]);

        expect(ActivityLog::count())->toBe(0);
    });

    it('marks the first save as such when the earlier file is unknown', function () {
        Queue::fake();

        $this->actingAs($this->user)
            ->put("/{$this->team->slug}/servers/{$this->server->id}/sites/{$this->site->id}/environment", [
                'env_content' => 'APP_NAME=MyApp',
            ]);

        expect(ActivityLog::sole()->properties)->toBe([
            'first_save' => true,
            'added_keys' => [],
            'changed_keys' => [],
            'removed_keys' => [],
        ]);
    });

    it('lists only this site\'s environment saves on the page', function () {
        ActivityLog::factory()->forSite($this->site)->create([
            'user_id' => $this->user->id,
            'properties' => ['first_save' => false, 'added_keys' => [], 'changed_keys' => ['DB_PASSWORD'], 'removed_keys' => []],
        ]);
        $otherSite = Site::factory()->create(['server_id' => $this->server->id]);
        ActivityLog::factory()->forSite($otherSite)->create();

        $this->actingAs($this->user)
            ->get("/{$this->team->slug}/servers/{$this->server->id}/sites/{$this->site->id}/environment")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('history', 1)
                ->where('history.0.changed_keys', ['DB_PASSWORD'])
                ->where('history.0.user_name', $this->user->name)
                ->where('history.0.is_first_save', false)
            );
    });
});
