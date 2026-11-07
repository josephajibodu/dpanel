<?php

use App\Events\SiteCommandRunStatusChanged;
use App\Models\Site;
use App\Models\SiteCommandRun;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('broadcasts only on its site channel, not to every site on the server', function () {
    $site = Site::factory()->create();
    $run = SiteCommandRun::factory()->forSite($site)->create();

    $event = new SiteCommandRunStatusChanged($run);

    expect(collect($event->broadcastOn())->pluck('name')->all())->toBe([
        "private-site.{$site->id}",
    ]);
});
