<?php

use App\Enums\ServerStatus;
use App\Jobs\CreateCronJobJob;
use App\Jobs\DestroyCronJobJob;
use App\Jobs\DisableCronJobJob;
use App\Jobs\EnableCronJobJob;
use App\Jobs\UpdateCronJobJob;
use App\Models\CronJob;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = Team::factory()->forUser($this->user)->create();
    $this->server = Server::factory()->forTeam($this->team)->create([
        'status' => ServerStatus::Active,
    ]);
});

it('stores a new cron job and dispatches job', function () {
    Queue::fake();

    $response = $this->actingAs($this->user)
        ->post("/servers/{$this->server->id}/cron-jobs", [
            'command' => 'php artisan schedule:run',
            'site_id' => null,
            'user' => 'deploy',
            'frequency' => '* * * * *',
        ]);

    $response->assertRedirect();

    $this->assertDatabaseHas('cron_jobs', [
        'server_id' => $this->server->id,
        'command' => 'php artisan schedule:run',
        'user' => 'deploy',
        'frequency' => '* * * * *',
        'hidden' => false,
        'status' => 'pending',
    ]);

    Queue::assertPushed(CreateCronJobJob::class);
});

it('validates cron job command and frequency are required', function () {
    $response = $this->actingAs($this->user)
        ->post("/servers/{$this->server->id}/cron-jobs", [
            'command' => '',
            'user' => 'deploy',
            'frequency' => '',
        ]);

    $response->assertSessionHasErrors(['command', 'frequency']);
    $this->assertDatabaseCount('cron_jobs', 0);
});

it('validates cron job site_id belongs to server', function () {
    $otherServer = Server::factory()->forTeam($this->team)->create();
    $site = \App\Models\Site::factory()->create([
        'server_id' => $otherServer->id,
    ]);

    $response = $this->actingAs($this->user)
        ->post("/servers/{$this->server->id}/cron-jobs", [
            'command' => 'php artisan schedule:run',
            'site_id' => $site->id,
            'user' => 'deploy',
            'frequency' => '* * * * *',
        ]);

    $response->assertSessionHasErrors('site_id');
    $this->assertDatabaseCount('cron_jobs', 0);
});

it('updates a cron job and dispatches job', function () {
    Queue::fake();

    $cronJob = CronJob::factory()->create([
        'server_id' => $this->server->id,
        'command' => 'old command',
        'frequency' => '* * * * *',
    ]);

    $response = $this->actingAs($this->user)
        ->put("/servers/{$this->server->id}/cron-jobs/{$cronJob->id}", [
            'command' => 'php artisan custom:task',
            'site_id' => null,
            'user' => 'www-data',
            'frequency' => '0 * * * *',
        ]);

    $response->assertRedirect();

    $cronJob->refresh();
    expect($cronJob->command)->toBe('php artisan custom:task')
        ->and($cronJob->user)->toBe('www-data')
        ->and($cronJob->frequency)->toBe('0 * * * *');

    Queue::assertPushed(UpdateCronJobJob::class);
});

it('dispatches job to destroy a cron job', function () {
    Queue::fake();

    $cronJob = CronJob::factory()->create([
        'server_id' => $this->server->id,
        'command' => 'to delete',
    ]);

    $response = $this->actingAs($this->user)
        ->delete("/servers/{$this->server->id}/cron-jobs/{$cronJob->id}");

    $response->assertRedirect();
    Queue::assertPushed(DestroyCronJobJob::class);
    $this->assertDatabaseHas('cron_jobs', ['id' => $cronJob->id]);
});

it('disables a cron job and dispatches job', function () {
    Queue::fake();

    $cronJob = CronJob::factory()->create([
        'server_id' => $this->server->id,
        'hidden' => false,
    ]);

    $response = $this->actingAs($this->user)
        ->patch("/servers/{$this->server->id}/cron-jobs/{$cronJob->id}/disable");

    $response->assertRedirect();
    $cronJob->refresh();
    expect($cronJob->hidden)->toBeTrue();
    Queue::assertPushed(DisableCronJobJob::class);
});

it('enables a cron job and dispatches job', function () {
    Queue::fake();

    $cronJob = CronJob::factory()->create([
        'server_id' => $this->server->id,
        'hidden' => true,
    ]);

    $response = $this->actingAs($this->user)
        ->patch("/servers/{$this->server->id}/cron-jobs/{$cronJob->id}/enable");

    $response->assertRedirect();
    $cronJob->refresh();
    expect($cronJob->hidden)->toBeFalse();
    Queue::assertPushed(EnableCronJobJob::class);
});

it('denies access to cron job from another server', function () {
    $otherServer = Server::factory()->forTeam($this->team)->create();
    $cronJob = CronJob::factory()->create(['server_id' => $otherServer->id]);

    $response = $this->actingAs($this->user)
        ->delete("/servers/{$this->server->id}/cron-jobs/{$cronJob->id}");

    $response->assertNotFound();
    $this->assertDatabaseHas('cron_jobs', ['id' => $cronJob->id]);
});
