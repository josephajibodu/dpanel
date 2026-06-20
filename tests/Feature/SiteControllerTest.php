<?php

use App\Enums\ConnectionStatus;
use App\Enums\ServerStatus;
use App\Enums\ServiceType;
use App\Jobs\SyncSiteNginxJob;
use App\Models\Server;
use App\Models\Site;
use App\Models\SourceControlAccount;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

it('edit page uses server installed PHP versions instead of hardcoded list', function () {
    $this->server->createService(ServiceType::Php, '8.3', true);
    $this->server->createService(ServiceType::Php, '8.1', false);

    $site = Site::factory()->forServer($this->server)->create();

    $response = $this->actingAs($this->user)
        ->get("/{$this->team->slug}/servers/{$this->server->id}/sites/{$site->id}/edit");

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('phpVersions', fn ($versions) => collect($versions)->pluck('value')->sort()->values()->all() === ['8.1', '8.3'])
        );
});

it('dispatches SyncSiteNginxJob when php_version changes and server is ready', function () {
    Queue::fake();

    $this->server->update(['connection_status' => ConnectionStatus::Successful]);
    $this->server->createService(ServiceType::Php, '8.3', true);
    $this->server->createService(ServiceType::Php, '8.2', false);

    $site = Site::factory()->forServer($this->server)->create(['php_version' => '8.3']);

    $this->actingAs($this->user)
        ->put("/{$this->team->slug}/servers/{$this->server->id}/sites/{$site->id}", [
            'php_version' => '8.2',
        ]);

    Queue::assertPushed(SyncSiteNginxJob::class, fn ($job) => $job->site->id === $site->id);
    expect($site->fresh()->php_version)->toBe('8.2');
});

it('does not dispatch SyncSiteNginxJob when php_version is unchanged', function () {
    Queue::fake();

    $this->server->update(['connection_status' => ConnectionStatus::Successful]);
    $this->server->createService(ServiceType::Php, '8.3', true);

    $site = Site::factory()->forServer($this->server)->create(['php_version' => '8.3']);

    $this->actingAs($this->user)
        ->put("/{$this->team->slug}/servers/{$this->server->id}/sites/{$site->id}", [
            'php_version' => '8.3',
        ]);

    Queue::assertNotPushed(SyncSiteNginxJob::class);
});

it('does not dispatch SyncSiteNginxJob when server is not ready', function () {
    Queue::fake();

    $this->server->update(['connection_status' => ConnectionStatus::Failed]);
    $this->server->createService(ServiceType::Php, '8.3', true);
    $this->server->createService(ServiceType::Php, '8.2', false);

    $site = Site::factory()->forServer($this->server)->create(['php_version' => '8.3']);

    $this->actingAs($this->user)
        ->put("/{$this->team->slug}/servers/{$this->server->id}/sites/{$site->id}", [
            'php_version' => '8.2',
        ]);

    Queue::assertNotPushed(SyncSiteNginxJob::class);
});

it('rejects php_version not installed on the server', function () {
    $this->server->createService(ServiceType::Php, '8.3', true);
    $site = Site::factory()->forServer($this->server)->create(['php_version' => '8.3']);

    $response = $this->actingAs($this->user)
        ->put("/{$this->team->slug}/servers/{$this->server->id}/sites/{$site->id}", [
            'php_version' => '8.1',
        ]);

    $response->assertSessionHasErrors('php_version');
});

it('shows site create page with source control accounts but without prefetched repositories', function () {
    SourceControlAccount::factory()
        ->forUser($this->user)
        ->count(2)
        ->create();

    $response = $this->actingAs($this->user)
        ->get("/{$this->team->slug}/servers/{$this->server->id}/sites/create");

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('sites/create')
            ->has('server')
            ->has('projectTypes')
            ->has('repositoryProviders')
            ->has('phpVersions')
            ->has('sourceControl')
            ->has('sourceControl.accounts')
            ->where('sourceControl.accounts.data', fn ($accounts) => count($accounts) === 2)
            ->missing('sourceControl.repositories')
        );
});
