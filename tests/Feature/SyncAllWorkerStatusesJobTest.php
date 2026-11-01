<?php

use App\Enums\ServerStatus;
use App\Jobs\SyncAllWorkerStatusesJob;
use App\Jobs\SyncWorkerStatusesJob;
use App\Models\Server;
use App\Models\Worker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('checks worker statuses only on active servers that have workers', function () {
    $activeWithWorkers = Server::factory()->create(['status' => ServerStatus::Active]);
    Worker::factory()->create(['server_id' => $activeWithWorkers->id]);

    Server::factory()->create(['status' => ServerStatus::Active]);

    $pendingWithWorkers = Server::factory()->create(['status' => ServerStatus::Pending]);
    Worker::factory()->create(['server_id' => $pendingWithWorkers->id]);

    Queue::fake([SyncWorkerStatusesJob::class]);

    (new SyncAllWorkerStatusesJob)->handle();

    Queue::assertPushed(SyncWorkerStatusesJob::class, 1);
    Queue::assertPushed(SyncWorkerStatusesJob::class, fn (SyncWorkerStatusesJob $job) => $job->server->id === $activeWithWorkers->id);
});
