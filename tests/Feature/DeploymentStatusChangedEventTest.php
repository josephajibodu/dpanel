<?php

use App\Events\DeploymentStatusChanged;
use App\Models\Deployment;
use App\Models\Server;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('broadcasts on the deployment, its site and its server channels', function () {
    $server = Server::factory()->create();
    $site = Site::factory()->forServer($server)->create();
    $deployment = Deployment::factory()->forSite($site)->create();

    $event = new DeploymentStatusChanged($deployment, 'finished');

    expect(collect($event->broadcastOn())->pluck('name')->all())->toBe([
        "private-deployments.{$deployment->id}",
        "private-site.{$site->id}",
        "private-server.{$server->id}",
    ]);
});
