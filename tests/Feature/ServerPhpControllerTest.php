<?php

use App\Enums\ConnectionStatus;
use App\Jobs\InstallPhpVersionJob;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

it('shows PHP index for the server', function () {
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
            ->has('settings')
            ->has('availableVersions')
            ->where('serverIsReady', false)
            ->where('defaultVersion', '8.3')
            ->where('availableVersions', ['8.1', '8.2', '8.3', '8.4'])
        );
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
