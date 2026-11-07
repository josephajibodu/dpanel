<?php

use App\Enums\ServerStatus;
use App\Enums\TeamRoleKey;
use App\Jobs\RestoreBackupJob;
use App\Models\Backup;
use App\Models\Server;
use App\Models\ServerDatabase;
use App\Models\StorageProvider;
use App\Models\Team;
use App\Models\User;
use App\Services\Storage\CloudflareR2Driver;
use App\Services\Storage\StorageProviderManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();

    $this->user = User::factory()->create();
    $this->team = Team::factory()->forUser($this->user)->create();
    $this->server = Server::factory()->forTeam($this->team)->create([
        'status' => ServerStatus::Active,
        'database_type' => 'mysql',
    ]);
    $this->serverDatabase = ServerDatabase::factory()->create([
        'server_id' => $this->server->id,
        'name' => 'app_production',
    ]);
    $this->storageProvider = StorageProvider::factory()->forTeam($this->team)->create();
    $this->importPath = "/{$this->team->slug}/servers/{$this->server->id}/databases/{$this->serverDatabase->id}/imports";

    $this->driver = Mockery::mock(CloudflareR2Driver::class);
    $storageManager = Mockery::mock(StorageProviderManager::class);
    $storageManager->shouldReceive('forAccount')->andReturn($this->driver);
    $this->app->instance(StorageProviderManager::class, $storageManager);
});

it('keeps the uploaded dump in storage and starts loading it into the database', function () {
    $this->driver->shouldReceive('putStream')->once()
        ->withArgs(fn (string $path) => str_starts_with($path, "imports/{$this->serverDatabase->id}/") && str_ends_with($path, '.sql.gz'));

    $this->actingAs($this->user)
        ->post($this->importPath, [
            'storage_provider_id' => $this->storageProvider->id,
            'dump' => UploadedFile::fake()->create('app.sql.gz', 120),
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $backup = $this->serverDatabase->backups()->sole();
    expect($backup->triggered_by)->toBe('import')
        ->and($backup->status)->toBe('completed')
        ->and($backup->restore_status)->toBe('running')
        ->and($backup->user_id)->toBe($this->user->id);

    Queue::assertPushed(RestoreBackupJob::class, fn (RestoreBackupJob $job) => $job->backup->is($backup));
});

it('only accepts .sql and .sql.gz files', function () {
    $this->actingAs($this->user)
        ->post($this->importPath, [
            'storage_provider_id' => $this->storageProvider->id,
            'dump' => UploadedFile::fake()->create('app.zip', 10),
        ])
        ->assertSessionHasErrors('dump');

    expect(Backup::count())->toBe(0);
});

it('refuses a storage provider from another team', function () {
    $otherProvider = StorageProvider::factory()->create();

    $this->actingAs($this->user)
        ->post($this->importPath, [
            'storage_provider_id' => $otherProvider->id,
            'dump' => UploadedFile::fake()->create('app.sql', 10),
        ])
        ->assertSessionHasErrors('storage_provider_id');

    expect(Backup::count())->toBe(0);
});

it('refuses to import into a Postgres database for now', function () {
    $this->server->update(['database_type' => 'postgresql']);

    $this->actingAs($this->user)
        ->post($this->importPath, [
            'storage_provider_id' => $this->storageProvider->id,
            'dump' => UploadedFile::fake()->create('app.sql', 10),
        ])
        ->assertSessionHas('error');

    expect(Backup::count())->toBe(0);
});

it('lets only members who may restore backups import a dump', function (TeamRoleKey $role, bool $allowed) {
    $this->driver->shouldReceive('putStream');
    $member = User::factory()->memberOf($this->team, $role)->create();

    $response = $this->actingAs($member)
        ->post($this->importPath, [
            'storage_provider_id' => $this->storageProvider->id,
            'dump' => UploadedFile::fake()->create('app.sql', 10),
        ]);

    $allowed ? $response->assertSessionHas('success') : $response->assertForbidden();
})->with([
    'admin' => [TeamRoleKey::Admin, true],
    'developer' => [TeamRoleKey::Developer, false],
    'viewer' => [TeamRoleKey::Viewer, false],
]);
