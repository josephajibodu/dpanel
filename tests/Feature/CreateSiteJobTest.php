<?php

use App\Enums\ServerStatus;
use App\Enums\SiteStatus;
use App\Events\ServerSitesUpdated;
use App\Jobs\CreateSiteJob;
use App\Models\Server;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

it('marks the site as failed with the exception message when the job fails outside the action', function () {
    Event::fake([ServerSitesUpdated::class]);

    $server = Server::factory()->create(['status' => ServerStatus::Active]);
    $site = Site::factory()->forServer($server)->pending()->create();

    (new CreateSiteJob($site))->failed(new RuntimeException('Job timed out'));

    $site->refresh();
    expect($site->status)->toBe(SiteStatus::Failed);
    expect($site->error_message)->toBe('Job timed out');

    Event::assertDispatched(ServerSitesUpdated::class);
});

it('does not overwrite an error already recorded by the provisioning action', function () {
    Event::fake([ServerSitesUpdated::class]);

    $server = Server::factory()->create(['status' => ServerStatus::Active]);
    $site = Site::factory()->forServer($server)->create([
        'status' => SiteStatus::Failed,
        'error_message' => 'git clone failed',
    ]);

    (new CreateSiteJob($site))->failed(new RuntimeException('Job timed out'));

    $site->refresh();
    expect($site->error_message)->toBe('git clone failed');

    Event::assertNotDispatched(ServerSitesUpdated::class);
});
