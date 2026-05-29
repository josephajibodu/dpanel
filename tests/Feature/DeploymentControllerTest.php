<?php

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

function storeDeploymentUrl(Team $team, Server $server, Site $site): string
{
    return "/{$team->slug}/servers/{$server->id}/sites/{$site->id}/deployments";
}

it('triggers a deployment and redirects to the new deployment', function () {
    $response = $this->actingAs($this->user)
        ->post(storeDeploymentUrl($this->team, $this->server, $this->site));

    $deployment = $this->site->deployments()->latest('id')->first();

    expect($deployment)->not->toBeNull()
        ->and($deployment->status)->toBe(DeploymentStatus::Pending)
        ->and($deployment->triggered_by)->toBe('manual');

    $response->assertRedirect(route('servers.sites.deployments.show', [$this->team, $this->server, $this->site, $deployment]));
    $response->assertSessionHas('deployment_started');

    Queue::assertPushed(DeploySiteJob::class);
});

it('redirects back with an error instead of failing when a deployment is already in progress', function () {
    Deployment::factory()->running()->forSite($this->site)->create();

    $response = $this->actingAs($this->user)
        ->from(route('servers.sites.deployments.index', [$this->team, $this->server, $this->site]))
        ->post(storeDeploymentUrl($this->team, $this->server, $this->site));

    $response->assertRedirect();
    $response->assertSessionHasErrors('deploy');

    expect($this->site->deployments()->count())->toBe(1);
    Queue::assertNotPushed(DeploySiteJob::class);
});

it('requires authentication to trigger a deployment', function () {
    $this->post(storeDeploymentUrl($this->team, $this->server, $this->site))
        ->assertRedirect('/login');

    Queue::assertNotPushed(DeploySiteJob::class);
});

it('prevents a non-team-member from triggering a deployment', function () {
    $outsider = User::factory()->create();

    $this->actingAs($outsider)
        ->post(storeDeploymentUrl($this->team, $this->server, $this->site))
        ->assertForbidden();

    Queue::assertNotPushed(DeploySiteJob::class);
});
