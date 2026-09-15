<?php

use App\Enums\ServerStatus;
use App\Models\Server;
use App\Models\ServerDatabase;
use App\Models\StorageProvider;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = Team::factory()->forUser($this->user)->create();
    $this->server = Server::factory()->forTeam($this->team)->create([
        'status' => ServerStatus::Active,
    ]);
    $this->serverDatabase = ServerDatabase::factory()->create(['server_id' => $this->server->id]);
    $this->storageProvider = StorageProvider::factory()->forTeam($this->team)->create();
});

it('creates a backup schedule for a database', function () {
    $response = $this->actingAs($this->user)
        ->put("/{$this->team->slug}/servers/{$this->server->id}/databases/{$this->serverDatabase->id}/backup-schedule", [
            'storage_provider_id' => $this->storageProvider->id,
            'frequency' => 'daily',
            'retention_count' => 7,
            'enabled' => true,
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');

    $this->assertDatabaseHas('backup_schedules', [
        'server_database_id' => $this->serverDatabase->id,
        'storage_provider_id' => $this->storageProvider->id,
        'frequency' => 'daily',
        'retention_count' => 7,
        'enabled' => true,
    ]);

    $schedule = $this->serverDatabase->backupSchedule()->first();
    expect($schedule->next_run_at)->not->toBeNull();
});

it('updates an existing schedule instead of creating a duplicate', function () {
    $this->actingAs($this->user)
        ->put("/{$this->team->slug}/servers/{$this->server->id}/databases/{$this->serverDatabase->id}/backup-schedule", [
            'storage_provider_id' => $this->storageProvider->id,
            'frequency' => 'daily',
            'retention_count' => 7,
            'enabled' => true,
        ]);

    $this->actingAs($this->user)
        ->put("/{$this->team->slug}/servers/{$this->server->id}/databases/{$this->serverDatabase->id}/backup-schedule", [
            'storage_provider_id' => $this->storageProvider->id,
            'frequency' => 'weekly',
            'retention_count' => 3,
            'enabled' => false,
        ]);

    $this->assertDatabaseCount('backup_schedules', 1);
    $this->assertDatabaseHas('backup_schedules', [
        'server_database_id' => $this->serverDatabase->id,
        'frequency' => 'weekly',
        'retention_count' => 3,
        'enabled' => false,
    ]);

    $schedule = $this->serverDatabase->backupSchedule()->first();
    expect($schedule->next_run_at)->toBeNull();
});

it('requires a storage provider belonging to the team', function () {
    $otherTeam = Team::factory()->forUser(User::factory()->create())->create();
    $foreignProvider = StorageProvider::factory()->forTeam($otherTeam)->create();

    $response = $this->actingAs($this->user)
        ->put("/{$this->team->slug}/servers/{$this->server->id}/databases/{$this->serverDatabase->id}/backup-schedule", [
            'storage_provider_id' => $foreignProvider->id,
            'frequency' => 'daily',
            'retention_count' => 7,
            'enabled' => true,
        ]);

    $response->assertSessionHasErrors('storage_provider_id');
});
