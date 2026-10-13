<?php

use App\Enums\ServerStatus;
use App\Jobs\CheckAllSitesUptimeJob;
use App\Jobs\CheckSiteUptimeJob;
use App\Models\Deployment;
use App\Models\Server;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->server = Server::factory()->create(['status' => ServerStatus::Active]);
});

it('dispatches an uptime check only for sites that should be serving traffic', function () {
    Queue::fake();

    $deployed = Site::factory()->forServer($this->server)->deployed()->create();
    $provisioned = Site::factory()->forServer($this->server)->provisioned()->create();
    $deploying = Site::factory()->forServer($this->server)->deploying()->create();
    $failedDeploy = Site::factory()->forServer($this->server)->failed()->create();
    Deployment::factory()->forSite($failedDeploy)->finished()->create();

    Site::factory()->forServer($this->server)->pending()->create();
    Site::factory()->forServer($this->server)->create(['status' => 'installing']);
    Site::factory()->forServer($this->server)->create(['status' => 'deleting']);
    Site::factory()->forServer($this->server)->failed()->create();

    $inactiveServer = Server::factory()->create(['status' => ServerStatus::Provisioning]);
    Site::factory()->forServer($inactiveServer)->deployed()->create();

    (new CheckAllSitesUptimeJob)->handle();

    $checkedSiteIds = [];
    Queue::assertPushed(CheckSiteUptimeJob::class, function (CheckSiteUptimeJob $job) use (&$checkedSiteIds) {
        $checkedSiteIds[] = $job->site->id;

        return true;
    });

    expect($checkedSiteIds)->toEqualCanonicalizing([
        $deployed->id,
        $provisioned->id,
        $deploying->id,
        $failedDeploy->id,
    ]);
});

it('skips sites without a primary domain', function () {
    Queue::fake();

    $site = Site::factory()->forServer($this->server)->deployed()->create();
    $site->domains()->delete();

    (new CheckAllSitesUptimeJob)->handle();

    Queue::assertNotPushed(CheckSiteUptimeJob::class);
});

it('has a single try and a 60-second timeout', function () {
    $job = new CheckAllSitesUptimeJob;

    expect($job->tries)->toBe(1)
        ->and($job->timeout)->toBe(60);
});
