<?php

use App\Actions\Sites\TriggerDeploymentAction;
use App\Enums\DeploymentStatus;
use App\Jobs\DeploySiteJob;
use App\Models\Deployment;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();

    $this->server = Server::factory()->create();
    $this->site = Site::factory()->forServer($this->server)->create();
});

it('creates a pending deployment and dispatches the deploy job', function () {
    $user = User::factory()->create();

    $deployment = (new TriggerDeploymentAction)->execute($this->site, 'manual', $user);

    expect($deployment->status)->toBe(DeploymentStatus::Pending)
        ->and($deployment->triggered_by)->toBe('manual')
        ->and($deployment->user_id)->toBe($user->id)
        ->and($this->site->fresh()->deployment_started_at)->not->toBeNull();

    Queue::assertPushed(DeploySiteJob::class, fn (DeploySiteJob $job) => $job->deployment->is($deployment));
    Queue::assertPushedOn('deploy', DeploySiteJob::class);
});

it('throws and dispatches nothing when a pending deployment already exists', function () {
    Deployment::factory()->pending()->forSite($this->site)->create();

    expect(fn () => (new TriggerDeploymentAction)->execute($this->site))
        ->toThrow(RuntimeException::class, 'already in progress');

    Queue::assertNotPushed(DeploySiteJob::class);
});

it('throws when a running deployment already exists', function () {
    Deployment::factory()->running()->forSite($this->site)->create();

    expect(fn () => (new TriggerDeploymentAction)->execute($this->site))
        ->toThrow(RuntimeException::class, 'already in progress');
});

it('enforces a single active deployment per site at the database level', function () {
    Deployment::factory()->pending()->forSite($this->site)->create();

    expect(fn () => Deployment::factory()->running()->forSite($this->site)->create())
        ->toThrow(QueryException::class);
});

it('allows many terminal-state deployments for the same site', function () {
    Deployment::factory()->finished()->forSite($this->site)->count(3)->create();
    Deployment::factory()->failed()->forSite($this->site)->create();
    Deployment::factory()->forSite($this->site)->create(['status' => DeploymentStatus::Cancelled]);

    expect($this->site->deployments()->count())->toBe(5);
});

it('allows concurrent active deployments on different sites', function () {
    $otherSite = Site::factory()->forServer($this->server)->create();

    Deployment::factory()->pending()->forSite($this->site)->create();
    Deployment::factory()->pending()->forSite($otherSite)->create();

    expect(Deployment::count())->toBe(2);
});

it('translates a lost race into the in-progress runtime exception', function () {
    // Deterministically reproduce the check-then-create race: after the action's
    // pre-check passes, a competing active deployment is inserted (via the model
    // "creating" hook) before the action's own INSERT lands. The partial unique
    // index then rejects the action's row, which must surface as the same clean
    // "already in progress" error rather than a raw database exception.
    $raced = false;
    $site = $this->site;

    Deployment::creating(function (Deployment $deployment) use (&$raced, $site) {
        if ($raced) {
            return;
        }
        $raced = true;
        Deployment::factory()->pending()->forSite($site)->create();
    });

    expect(fn () => (new TriggerDeploymentAction)->execute($site))
        ->toThrow(RuntimeException::class, 'already in progress');

    expect($site->deployments()->count())->toBe(1);
    Queue::assertNotPushed(DeploySiteJob::class);
});
