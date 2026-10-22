<?php

use App\Models\CronJob;
use App\Models\Server;
use App\Models\Worker;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function runMoveToServerUserMigration(): void
{
    (require database_path('migrations/2026_10_22_125213_move_workers_and_cron_jobs_to_server_user.php'))->up();
}

it('moves workers and cron jobs saved with another user onto the server user', function (?string $oldUser) {
    $server = Server::factory()->create();
    $worker = Worker::factory()->create(['server_id' => $server->id, 'user' => $oldUser]);
    $cron = CronJob::factory()->create(['server_id' => $server->id, 'user' => $oldUser]);

    runMoveToServerUserMigration();

    expect($worker->fresh()->user)->toBe('artisan')
        ->and($cron->fresh()->user)->toBe('artisan')
        ->and($worker->fresh()->supervisorConfig('w'))->toContain("user=artisan\n")
        ->and($cron->fresh()->cronLine())->toStartWith('* * * * * artisan ');
})->with(['root', 'www-data', 'deploy', null]);

it('leaves workers and cron jobs already on the server user untouched', function () {
    $server = Server::factory()->create();
    $worker = Worker::factory()->create(['server_id' => $server->id, 'user' => 'artisan']);
    $updatedAt = $worker->updated_at;

    $this->travel(1)->minute();
    runMoveToServerUserMigration();

    expect($worker->fresh()->user)->toBe('artisan')
        ->and($worker->fresh()->updated_at->equalTo($updatedAt))->toBeTrue();
});
