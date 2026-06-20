<?php

use App\Actions\ServerPhp\GetPhpInfo;
use App\Enums\ConnectionStatus;
use App\Enums\ServiceType;
use App\Jobs\InstallPhpVersionJob;
use App\Jobs\SetDefaultPhpVersionJob;
use App\Jobs\SyncSiteNginxJob;
use App\Jobs\UpdatePhpSettingsJob;
use App\Models\Server;
use App\Models\Site;
use App\Models\Team;
use App\Models\User;
use App\Services\Ssh\SshService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = Team::factory()->forUser($this->user)->create();
    $this->server = Server::factory()->forTeam($this->team)->create([
        'status' => \App\Enums\ServerStatus::Active,
        'connection_status' => ConnectionStatus::Failed,
        'php_version' => '8.3',
    ]);
});

it('shows PHP index for the server without SSH on initial load', function () {
    $response = $this->actingAs($this->user)
        ->get("/{$this->team->slug}/servers/{$this->server->id}/php");

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('servers/php/index')
            ->has('server')
            ->has('serverIsReady')
            ->has('phpServices')
            ->has('installedVersions')
            ->has('defaultVersion')
            ->has('availableVersions')
            ->where('serverIsReady', false)
            ->where('defaultVersion', '8.3')
            ->where('availableVersions', ['8.1', '8.2', '8.3', '8.4'])
        );
});

it('derives installed versions from database services without SSH', function () {
    $this->server->createService(ServiceType::Php, '8.3', true);
    $this->server->createService(ServiceType::Php, '8.1', false);

    $mockSsh = Mockery::mock(SshService::class);
    $mockSsh->shouldNotReceive('connect');
    $this->app->instance(SshService::class, $mockSsh);

    $response = $this->actingAs($this->user)
        ->get("/{$this->team->slug}/servers/{$this->server->id}/php");

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('installedVersions', ['8.1', '8.3'])
        );
});

it('fetches settings via SSH when cache is cold', function () {
    $this->server->update(['connection_status' => ConnectionStatus::Successful]);

    $connMock = Mockery::mock(\App\Services\Ssh\SshConnection::class);
    $connMock->shouldReceive('sudo')
        ->once()
        ->andReturn("upload_max_filesize = 32M\nmemory_limit = 128M\n");
    $connMock->shouldReceive('disconnect');

    $sshMock = Mockery::mock(SshService::class);
    $sshMock->shouldReceive('connect')->once()->andReturn($connMock);
    $this->app->instance(SshService::class, $sshMock);

    $action = app(GetPhpInfo::class);
    $result = $action->execute($this->server);

    expect($result)->toMatchArray(['upload_max_filesize' => '32M', 'memory_limit' => '128M']);
    expect(Cache::has(GetPhpInfo::cacheKey($this->server->id, '8.3')))->toBeTrue();
});

it('serves cached settings without SSH on repeated calls', function () {
    $this->server->update(['connection_status' => ConnectionStatus::Successful]);

    Cache::put(
        GetPhpInfo::cacheKey($this->server->id, '8.3'),
        ['upload_max_filesize' => '64M', 'memory_limit' => '256M'],
        300,
    );

    $mockSsh = Mockery::mock(SshService::class);
    $mockSsh->shouldNotReceive('connect');
    $this->app->instance(SshService::class, $mockSsh);

    $action = app(GetPhpInfo::class);
    $result = $action->execute($this->server);

    expect($result)->toBe(['upload_max_filesize' => '64M', 'memory_limit' => '256M']);
});

it('dispatches UpdatePhpSettingsJob and stores pending status in type_data', function () {
    Queue::fake();

    $this->server->update(['connection_status' => ConnectionStatus::Successful]);
    $phpService = $this->server->createService(ServiceType::Php, '8.3', true);

    $mockSsh = Mockery::mock(SshService::class);
    $mockSsh->shouldNotReceive('connect');
    $this->app->instance(SshService::class, $mockSsh);

    $response = $this->actingAs($this->user)
        ->put("/{$this->team->slug}/servers/{$this->server->id}/php/settings", [
            'upload_max_filesize' => '64M',
            'max_execution_time' => 30,
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');

    Queue::assertPushed(UpdatePhpSettingsJob::class, fn ($job) => $job->phpService->id === $phpService->id);

    $phpService->refresh();
    expect($phpService->type_data['settings_sync_status'])->toBe('pending');
    expect($phpService->type_data['settings']['upload_max_filesize'])->toBe('64M');
    expect($phpService->type_data['settings']['max_execution_time'])->toBe(30);
});

it('denies guest access to PHP index', function () {
    $response = $this->get("/{$this->team->slug}/servers/{$this->server->id}/php");

    $response->assertRedirect();
});

it('denies access to PHP index for another users server', function () {
    $otherUser = User::factory()->create();

    $response = $this->actingAs($otherUser)
        ->get("/{$this->team->slug}/servers/{$this->server->id}/php");

    $response->assertForbidden();
});

it('redirects with error when updating settings and server is not ready', function () {
    $response = $this->actingAs($this->user)
        ->put("/{$this->team->slug}/servers/{$this->server->id}/php/settings", [
            'upload_max_filesize' => '64M',
            'max_execution_time' => 30,
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('error');
});

it('validates update settings request', function () {
    $this->server->update(['connection_status' => ConnectionStatus::Successful]);

    $response = $this->actingAs($this->user)
        ->put("/{$this->team->slug}/servers/{$this->server->id}/php/settings", [
            'max_execution_time' => 99999,
        ]);

    $response->assertSessionHasErrors('max_execution_time');
});

it('redirects with error when installing version and server is not ready', function () {
    $response = $this->actingAs($this->user)
        ->post("/{$this->team->slug}/servers/{$this->server->id}/php/versions", [
            'version' => '8.2',
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('error');
});

it('validates install version request', function () {
    $response = $this->actingAs($this->user)
        ->post("/{$this->team->slug}/servers/{$this->server->id}/php/versions", [
            'version' => '7.4',
        ]);

    $response->assertSessionHasErrors('version');
});

it('dispatches job to install PHP version when server is ready', function () {
    \Illuminate\Support\Facades\Queue::fake();

    $this->server->update(['connection_status' => ConnectionStatus::Successful]);

    $response = $this->actingAs($this->user)
        ->post("/{$this->team->slug}/servers/{$this->server->id}/php/versions", [
            'version' => '8.2',
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');

    \Illuminate\Support\Facades\Queue::assertPushed(InstallPhpVersionJob::class, function ($job) {
        return $job->service->server_id === $this->server->id && $job->service->version === '8.2';
    });
});

it('redirects with error when setting default version and server is not ready', function () {
    $response = $this->actingAs($this->user)
        ->patch("/{$this->team->slug}/servers/{$this->server->id}/php/default-version", [
            'version' => '8.3',
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('error');
});

it('validates set default version request', function () {
    $response = $this->actingAs($this->user)
        ->patch("/{$this->team->slug}/servers/{$this->server->id}/php/default-version", [
            'version' => '7.4',
        ]);

    $response->assertSessionHasErrors('version');
});

it('dispatches SyncSiteNginxJob for all sites when upgrade_sites is true', function () {
    Queue::fake();

    $this->server->update(['connection_status' => ConnectionStatus::Successful]);
    $this->server->createService(ServiceType::Php, '8.3', true);
    $this->server->createService(ServiceType::Php, '8.2', false);

    $site1 = Site::factory()->forServer($this->server)->create(['php_version' => '8.3']);
    $site2 = Site::factory()->forServer($this->server)->create(['php_version' => '8.3']);

    $response = $this->actingAs($this->user)
        ->patch("/{$this->team->slug}/servers/{$this->server->id}/php/default-version", [
            'version' => '8.2',
            'upgrade_sites' => true,
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');

    Queue::assertPushed(SetDefaultPhpVersionJob::class, 1);
    Queue::assertPushed(SyncSiteNginxJob::class, 2);
    expect($site1->fresh()->php_version)->toBe('8.2');
    expect($site2->fresh()->php_version)->toBe('8.2');
});

it('does not dispatch SyncSiteNginxJob when upgrade_sites is false', function () {
    Queue::fake();

    $this->server->update(['connection_status' => ConnectionStatus::Successful]);
    $this->server->createService(ServiceType::Php, '8.3', true);
    $this->server->createService(ServiceType::Php, '8.2', false);

    Site::factory()->forServer($this->server)->create(['php_version' => '8.3']);

    $response = $this->actingAs($this->user)
        ->patch("/{$this->team->slug}/servers/{$this->server->id}/php/default-version", [
            'version' => '8.2',
        ]);

    $response->assertRedirect();
    Queue::assertPushed(SetDefaultPhpVersionJob::class, 1);
    Queue::assertNotPushed(SyncSiteNginxJob::class);
});
