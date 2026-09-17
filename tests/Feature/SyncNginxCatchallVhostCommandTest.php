<?php

use App\Actions\Servers\SyncNginxCatchallVhostAction;
use App\Enums\ServerStatus;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('requires --server or --all', function () {
    $this->artisan('flitops:nginx:sync-catchall')
        ->assertFailed();
});

it('targets only the given --server ids', function () {
    $target = Server::factory()->create(['status' => ServerStatus::Active]);
    $other = Server::factory()->create(['status' => ServerStatus::Active]);

    $action = Mockery::mock(SyncNginxCatchallVhostAction::class);
    $action->shouldReceive('execute')->once()->with(Mockery::on(fn ($s) => $s->id === $target->id));
    $this->app->instance(SyncNginxCatchallVhostAction::class, $action);

    $this->artisan('flitops:nginx:sync-catchall', ['--server' => [$target->id]])
        ->assertSuccessful();

    expect($other)->not->toBeNull();
});

it('targets every active server with --all and reports a failure without aborting the batch', function () {
    $good = Server::factory()->create(['status' => ServerStatus::Active]);
    $bad = Server::factory()->create(['status' => ServerStatus::Active]);
    Server::factory()->create(['status' => ServerStatus::Pending]);

    $action = Mockery::mock(SyncNginxCatchallVhostAction::class);
    $action->shouldReceive('execute')->once()->with(Mockery::on(fn ($s) => $s->id === $good->id));
    $action->shouldReceive('execute')->once()->with(Mockery::on(fn ($s) => $s->id === $bad->id))
        ->andThrow(new RuntimeException('nginx -t failed'));
    $this->app->instance(SyncNginxCatchallVhostAction::class, $action);

    $this->artisan('flitops:nginx:sync-catchall', ['--all' => true])
        ->assertFailed();
});
