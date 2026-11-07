<?php

use App\Events\ServerSitesUpdated;
use App\Models\Server;
use App\Models\Site;

it('broadcasts a site change on the server channel and on that site channel', function () {
    $server = Server::factory()->create();
    $site = Site::factory()->forServer($server)->create();

    $event = new ServerSitesUpdated($server, $site);

    expect(collect($event->broadcastOn())->pluck('name')->all())->toBe([
        "private-server.{$server->id}",
        "private-site.{$site->id}",
    ]);
});

it('broadcasts only on the server channel when no single site changed', function () {
    $server = Server::factory()->create();

    $event = new ServerSitesUpdated($server);

    expect(collect($event->broadcastOn())->pluck('name')->all())->toBe([
        "private-server.{$server->id}",
    ]);
});
