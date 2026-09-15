<?php

use App\Jobs\CollectAllServerMetricsJob;
use App\Jobs\CollectServerMetricsJob;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = Team::factory()->forUser($this->user)->create();
    $this->user->switchTeam($this->team);
});

it('dispatches CollectServerMetricsJob for active servers only', function () {
    Queue::fake();

    $active = Server::factory()->custom()->active()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
    ]);
    Server::factory()->custom()->pending()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
    ]);

    (new CollectAllServerMetricsJob)->handle();

    Queue::assertPushed(CollectServerMetricsJob::class, 1);
    Queue::assertPushed(CollectServerMetricsJob::class, fn ($job) => $job->server->id === $active->id);
});

it('has a single try and a 60-second timeout', function () {
    $job = new CollectAllServerMetricsJob;

    expect($job->tries)->toBe(1)
        ->and($job->timeout)->toBe(60);
});
