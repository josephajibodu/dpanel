<?php

use App\Enums\ServerStatus;
use App\Models\CronJob;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->server = Server::factory()->create([
        'user_id' => $this->user->id,
        'status' => ServerStatus::Active,
    ]);
});

it('stores a new cron job', function () {
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
        'status' => 'active',
    ]);
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
    $otherServer = Server::factory()->create(['user_id' => $this->user->id]);
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

it('updates a cron job', function () {
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
});

it('destroys a cron job', function () {
    $cronJob = CronJob::factory()->create([
        'server_id' => $this->server->id,
        'command' => 'to delete',
    ]);

    $response = $this->actingAs($this->user)
        ->delete("/servers/{$this->server->id}/cron-jobs/{$cronJob->id}");

    $response->assertRedirect();
    $this->assertDatabaseMissing('cron_jobs', ['id' => $cronJob->id]);
});

it('disables a cron job', function () {
    $cronJob = CronJob::factory()->create([
        'server_id' => $this->server->id,
        'hidden' => false,
    ]);

    $response = $this->actingAs($this->user)
        ->patch("/servers/{$this->server->id}/cron-jobs/{$cronJob->id}/disable");

    $response->assertRedirect();
    $cronJob->refresh();
    expect($cronJob->hidden)->toBeTrue();
});

it('enables a cron job', function () {
    $cronJob = CronJob::factory()->create([
        'server_id' => $this->server->id,
        'hidden' => true,
    ]);

    $response = $this->actingAs($this->user)
        ->patch("/servers/{$this->server->id}/cron-jobs/{$cronJob->id}/enable");

    $response->assertRedirect();
    $cronJob->refresh();
    expect($cronJob->hidden)->toBeFalse();
});

it('denies access to cron job from another server', function () {
    $otherServer = Server::factory()->create(['user_id' => $this->user->id]);
    $cronJob = CronJob::factory()->create(['server_id' => $otherServer->id]);

    $response = $this->actingAs($this->user)
        ->delete("/servers/{$this->server->id}/cron-jobs/{$cronJob->id}");

    $response->assertNotFound();
    $this->assertDatabaseHas('cron_jobs', ['id' => $cronJob->id]);
});
