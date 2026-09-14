<?php

use App\Actions\Sites\RollbackDeploymentAction;
use App\Enums\DeploymentStatus;
use App\Enums\SiteStatus;
use App\Exceptions\DeploymentFailedException;
use App\Jobs\RollbackSiteJob;
use App\Models\Deployment;
use App\Models\Server;
use App\Models\Site;
use App\Models\Team;
use App\Models\User;
use App\Services\Deployment\DeploymentStrategy;
use App\Services\Ssh\SshConnection;
use App\Services\Ssh\SshService;
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
// RollbackDeploymentAction unit tests
// ---------------------------------------------------------------------------

it('creates a rollback deployment reusing the target release', function () {
    $target = Deployment::factory()->finished()->forSite($this->site)->create([
        'commit_hash' => 'abc1234abc1234abc1234abc1234abc1234abcd',
        'commit_message' => 'Fix the thing',
        'commit_author' => 'Jane Developer',
    ]);

    $rollback = (new RollbackDeploymentAction)->execute($this->site, $target, $this->user);

    expect($rollback->status)->toBe(DeploymentStatus::Pending)
        ->and($rollback->triggered_by)->toBe('rollback')
        ->and($rollback->rollback_of_deployment_id)->toBe($target->id)
        ->and($rollback->release_path)->toBe($target->ulid)
        ->and($rollback->releaseFolderName())->toBe($target->ulid)
        ->and($rollback->commit_hash)->toBe($target->commit_hash)
        ->and($rollback->commit_message)->toBe('Fix the thing');

    Queue::assertPushed(RollbackSiteJob::class, fn (RollbackSiteJob $job) => $job->deployment->is($rollback));
});

it('cannot roll back to a deployment from another site', function () {
    $otherSite = Site::factory()->forServer($this->server)->create();
    $target = Deployment::factory()->finished()->forSite($otherSite)->create();

    expect(fn () => (new RollbackDeploymentAction)->execute($this->site, $target))
        ->toThrow(\RuntimeException::class, 'does not belong to this site');

    Queue::assertNotPushed(RollbackSiteJob::class);
});

it('cannot roll back to a deployment that is not finished', function () {
    $target = Deployment::factory()->failed()->forSite($this->site)->create();

    expect(fn () => (new RollbackDeploymentAction)->execute($this->site, $target))
        ->toThrow(\RuntimeException::class, 'Only a finished deployment can be rolled back to.');

    Queue::assertNotPushed(RollbackSiteJob::class);
});

it('cannot roll back when a deployment is already in progress', function () {
    $target = Deployment::factory()->finished()->forSite($this->site)->create();
    Deployment::factory()->running()->forSite($this->site)->create();

    expect(fn () => (new RollbackDeploymentAction)->execute($this->site, $target))
        ->toThrow(\RuntimeException::class, 'already in progress');

    Queue::assertNotPushed(RollbackSiteJob::class);
});

// ---------------------------------------------------------------------------
// HTTP rollback endpoint tests
// ---------------------------------------------------------------------------

it('rolls back to a finished deployment via the http endpoint', function () {
    $target = Deployment::factory()->finished()->forSite($this->site)->create();

    $response = $this->actingAs($this->user)
        ->post("/{$this->team->slug}/servers/{$this->server->id}/sites/{$this->site->id}/deployments/{$target->id}/rollback");

    $response->assertRedirect();
    Queue::assertPushed(RollbackSiteJob::class);
    expect($this->site->deployments()->where('triggered_by', 'rollback')->exists())->toBeTrue();
});

it('returns an error when rolling back to a non-finished deployment via http', function () {
    $target = Deployment::factory()->pending()->forSite($this->site)->create();

    $response = $this->actingAs($this->user)
        ->from("/{$this->team->slug}/servers/{$this->server->id}/sites/{$this->site->id}/deployments/{$target->id}")
        ->post("/{$this->team->slug}/servers/{$this->server->id}/sites/{$this->site->id}/deployments/{$target->id}/rollback");

    $response->assertRedirect();
    $response->assertSessionHasErrors('rollback');
    Queue::assertNotPushed(RollbackSiteJob::class);
});

it('requires authentication to roll back a deployment', function () {
    $target = Deployment::factory()->finished()->forSite($this->site)->create();

    $this->post("/{$this->team->slug}/servers/{$this->server->id}/sites/{$this->site->id}/deployments/{$target->id}/rollback")
        ->assertRedirect('/login');
});

it('prevents a non-team-member from rolling back a deployment', function () {
    $outsider = User::factory()->create();
    $target = Deployment::factory()->finished()->forSite($this->site)->create();

    $this->actingAs($outsider)
        ->post("/{$this->team->slug}/servers/{$this->server->id}/sites/{$this->site->id}/deployments/{$target->id}/rollback")
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// RollbackSiteJob
// ---------------------------------------------------------------------------

it('swaps current to the target release and marks the rollback finished', function () {
    $target = Deployment::factory()->finished()->forSite($this->site)->create();
    $rollback = Deployment::factory()->pending()->forSite($this->site)->create([
        'triggered_by' => 'rollback',
        'rollback_of_deployment_id' => $target->id,
        'release_path' => $target->ulid,
    ]);

    $swapCommand = null;
    $connection = Mockery::mock(SshConnection::class);
    $connection->shouldReceive('directoryExists')->once()->andReturnTrue();
    $connection->shouldReceive('exec')->once()->andReturnUsing(function (string $command) use (&$swapCommand) {
        $swapCommand = $command;

        return '';
    });
    $connection->shouldReceive('disconnect')->once();

    $sshService = Mockery::mock(SshService::class);
    $sshService->shouldReceive('connect')->once()->andReturn($connection);

    $job = new RollbackSiteJob($rollback);
    $job->handle($sshService, app(DeploymentStrategy::class));

    expect($swapCommand)->toContain('ln -sfn')
        ->and($swapCommand)->toContain($target->ulid);

    $rollback->refresh();
    $this->site->refresh();

    expect($rollback->status)->toBe(DeploymentStatus::Finished)
        ->and($this->site->status)->toBe(SiteStatus::Deployed);
});

it('fails the rollback when the target release no longer exists on disk', function () {
    $target = Deployment::factory()->finished()->forSite($this->site)->create();
    $rollback = Deployment::factory()->pending()->forSite($this->site)->create([
        'triggered_by' => 'rollback',
        'rollback_of_deployment_id' => $target->id,
        'release_path' => $target->ulid,
    ]);

    $connection = Mockery::mock(SshConnection::class);
    $connection->shouldReceive('directoryExists')->once()->andReturnFalse();
    $connection->shouldReceive('exec')->never();
    $connection->shouldReceive('disconnect')->once();

    $sshService = Mockery::mock(SshService::class);
    $sshService->shouldReceive('connect')->once()->andReturn($connection);

    $job = new RollbackSiteJob($rollback);

    expect(fn () => $job->handle($sshService, app(DeploymentStrategy::class)))
        ->toThrow(DeploymentFailedException::class, 'no longer exists');

    expect($rollback->fresh()->status)->toBe(DeploymentStatus::Failed);
});

it('skips ssh execution when the rollback is cancelled before the job runs', function () {
    $target = Deployment::factory()->finished()->forSite($this->site)->create();
    $rollback = Deployment::factory()->pending()->forSite($this->site)->create([
        'triggered_by' => 'rollback',
        'rollback_of_deployment_id' => $target->id,
        'release_path' => $target->ulid,
        'status' => DeploymentStatus::Cancelled,
    ]);

    $sshService = Mockery::mock(SshService::class);
    $sshService->shouldNotReceive('connect');

    $job = new RollbackSiteJob($rollback);
    $job->handle($sshService, app(DeploymentStrategy::class));

    expect($rollback->fresh()->status)->toBe(DeploymentStatus::Cancelled);
});
