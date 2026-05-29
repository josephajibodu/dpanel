<?php

use App\Actions\Sites\CancelDeploymentAction;
use App\Enums\DeploymentStatus;
use App\Jobs\DeploySiteJob;
use App\Models\Deployment;
use App\Models\Server;
use App\Models\Site;
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
    $this->server = Server::factory()->forTeam($this->team)->create();
    $this->site = Site::factory()->forServer($this->server)->create();
});

// ---------------------------------------------------------------------------
// CancelDeploymentAction unit tests
// ---------------------------------------------------------------------------

it('cancels a pending deployment', function () {
    $deployment = Deployment::factory()->pending()->forSite($this->site)->create();

    (new CancelDeploymentAction)->execute($deployment);

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Cancelled);
});

it('cannot cancel a running deployment', function () {
    $deployment = Deployment::factory()->running()->forSite($this->site)->create();

    expect(fn () => (new CancelDeploymentAction)->execute($deployment))
        ->toThrow(\RuntimeException::class, 'already running');
});

it('cannot cancel a finished deployment', function () {
    $deployment = Deployment::factory()->finished()->forSite($this->site)->create();

    expect(fn () => (new CancelDeploymentAction)->execute($deployment))
        ->toThrow(\RuntimeException::class, 'Only pending deployments can be cancelled.');
});

it('cannot cancel a failed deployment', function () {
    $deployment = Deployment::factory()->failed()->forSite($this->site)->create();

    expect(fn () => (new CancelDeploymentAction)->execute($deployment))
        ->toThrow(\RuntimeException::class, 'Only pending deployments can be cancelled.');
});

it('cannot cancel an already-cancelled deployment', function () {
    $deployment = Deployment::factory()->forSite($this->site)->create([
        'status' => DeploymentStatus::Cancelled,
    ]);

    expect(fn () => (new CancelDeploymentAction)->execute($deployment))
        ->toThrow(\RuntimeException::class, 'Only pending deployments can be cancelled.');
});

// ---------------------------------------------------------------------------
// HTTP cancel endpoint tests
// ---------------------------------------------------------------------------

it('cancels a pending deployment via the http endpoint', function () {
    $deployment = Deployment::factory()->pending()->forSite($this->site)->create();

    $response = $this->actingAs($this->user)
        ->post("/{$this->team->slug}/servers/{$this->server->id}/sites/{$this->site->id}/deployments/{$deployment->id}/cancel");

    $response->assertRedirect();
    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Cancelled);
});

it('returns an error when cancelling a running deployment via http', function () {
    $deployment = Deployment::factory()->running()->forSite($this->site)->create();

    $response = $this->actingAs($this->user)
        ->from("/{$this->team->slug}/servers/{$this->server->id}/sites/{$this->site->id}/deployments/{$deployment->id}")
        ->post("/{$this->team->slug}/servers/{$this->server->id}/sites/{$this->site->id}/deployments/{$deployment->id}/cancel");

    $response->assertRedirect();
    $response->assertSessionHasErrors('cancel');
    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Running);
});

it('requires authentication to cancel a deployment', function () {
    $deployment = Deployment::factory()->pending()->forSite($this->site)->create();

    $this->post("/{$this->team->slug}/servers/{$this->server->id}/sites/{$this->site->id}/deployments/{$deployment->id}/cancel")
        ->assertRedirect('/login');
});

it('prevents a non-team-member from cancelling a deployment', function () {
    $outsider = User::factory()->create();
    $deployment = Deployment::factory()->pending()->forSite($this->site)->create();

    $this->actingAs($outsider)
        ->post("/{$this->team->slug}/servers/{$this->server->id}/sites/{$this->site->id}/deployments/{$deployment->id}/cancel")
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// DeploySiteJob cancellation guard
// ---------------------------------------------------------------------------

it('skips ssh execution when the deployment is cancelled before the job runs', function () {
    $deployment = Deployment::factory()->pending()->forSite($this->site)->create();

    // Simulate cancellation arriving while the job was sitting in the queue.
    $deployment->update(['status' => DeploymentStatus::Cancelled]);

    $sshService = Mockery::mock(\App\Services\Ssh\SshService::class);
    $sshService->shouldNotReceive('connect');

    $job = new DeploySiteJob($deployment);
    $job->handle($sshService);

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Cancelled);
});
