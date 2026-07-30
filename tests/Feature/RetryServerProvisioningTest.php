<?php

use App\Enums\ProvisioningStep;
use App\Enums\ServerStatus;
use App\Jobs\InstallStackJob;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();

    $this->user = User::factory()->create();
    $this->team = Team::factory()->forUser($this->user)->create();
    $this->user->switchTeam($this->team);
});

it('retries a failed provision by re-dispatching InstallStackJob and clearing the error', function () {
    $server = Server::factory()->forTeam($this->team)->error()->create([
        'provisioning_step' => ProvisioningStep::InstallingNginx,
        'error_message' => 'apt-get failed',
    ]);

    $response = $this->actingAs($this->user)
        ->post(route('servers.provision', [$this->team, $server]));

    $response->assertRedirect(route('servers.show', [$this->team, $server]));
    $response->assertSessionHas('success', 'Retrying provisioning...');

    $server->refresh();

    expect($server->status)->toBe(ServerStatus::Provisioning)
        ->and($server->provisioning_step)->toBe(ProvisioningStep::WaitingForServer)
        ->and($server->error_message)->toBeNull()
        ->and($server->provisioningLogs()->where('message', 'Retrying provisioning...')->exists())->toBeTrue();

    Queue::assertPushed(InstallStackJob::class, fn (InstallStackJob $job) => $job->server->is($server));
});

it('starts provisioning for a pending server without treating it as a retry', function () {
    $server = Server::factory()->forTeam($this->team)->pending()->create();

    $response = $this->actingAs($this->user)
        ->post(route('servers.provision', [$this->team, $server]));

    $response->assertSessionHas('success', 'Server provisioning started...');

    $server->refresh();

    expect($server->status)->toBe(ServerStatus::Provisioning)
        ->and($server->provisioningLogs()->count())->toBe(0);

    Queue::assertPushed(InstallStackJob::class);
});

it('does not allow retrying a server that is not pending or errored', function () {
    $server = Server::factory()->forTeam($this->team)->active()->create();

    $response = $this->actingAs($this->user)
        ->post(route('servers.provision', [$this->team, $server]));

    $response->assertRedirect(route('servers.show', [$this->team, $server]));

    $server->refresh();

    expect($server->status)->toBe(ServerStatus::Active);

    Queue::assertNotPushed(InstallStackJob::class);
});

it('does not allow a user outside the team to retry provisioning', function () {
    $server = Server::factory()->forTeam($this->team)->error()->create();
    $outsider = User::factory()->create();

    $response = $this->actingAs($outsider)
        ->post(route('servers.provision', [$this->team, $server]));

    $response->assertForbidden();

    Queue::assertNotPushed(InstallStackJob::class);
});
