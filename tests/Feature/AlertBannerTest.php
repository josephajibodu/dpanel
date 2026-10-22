<?php

use App\Actions\Backups\RunDatabaseBackup;
use App\Jobs\RunBackupJob;
use App\Models\Backup;
use App\Models\Server;
use App\Models\ServerDatabase;
use App\Models\StorageProvider;
use App\Models\Team;
use App\Models\User;
use App\Notifications\DatabaseBackupFailed;
use App\Services\Storage\CloudflareR2Driver;
use App\Services\Storage\StorageProviderManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = Team::factory()->forUser($this->user)->create();
    $this->server = Server::factory()->forTeam($this->team)->create(['name' => 'web-1']);
    $this->serverDatabase = ServerDatabase::factory()->create([
        'server_id' => $this->server->id,
        'name' => 'tributes',
    ]);
    $this->backup = Backup::factory()->create([
        'server_database_id' => $this->serverDatabase->id,
        'storage_provider_id' => StorageProvider::factory()->create()->id,
        'user_id' => $this->user->id,
        'status' => 'failed',
        'error_message' => 'Cloudflare R2 didn\'t accept the access key on the "Primary R2" storage provider.',
    ]);
});

it('shows a failed backup in the banner on every page', function (string $path) {
    $this->user->notify(new DatabaseBackupFailed($this->backup));

    $this->actingAs($this->user)
        ->get(str_replace('{team}', $this->team->slug, $path))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('alerts', 1)
            ->where('alerts.0.title', 'Backup of tributes on web-1 failed')
            ->where('alerts.0.message', $this->backup->error_message)
            ->where('alerts.0.url', $this->backup->indexUrl()));
})->with([
    'dashboard' => '/{team}/dashboard',
    'ssh keys' => '/{team}/ssh-keys',
]);

it('hides an alert once it is dismissed', function () {
    $this->user->notify(new DatabaseBackupFailed($this->backup));
    $notification = $this->user->notifications()->first();

    $this->actingAs($this->user)
        ->from("/{$this->team->slug}/dashboard")
        ->post(route('notifications.dismiss', $notification->id))
        ->assertRedirect("/{$this->team->slug}/dashboard");

    expect($notification->fresh()->read_at)->not->toBeNull();

    $this->actingAs($this->user)
        ->get("/{$this->team->slug}/dashboard")
        ->assertInertia(fn (AssertableInertia $page) => $page->has('alerts', 0));
});

it('does not let a user dismiss someone else\'s alert', function () {
    $this->user->notify(new DatabaseBackupFailed($this->backup));
    $notification = $this->user->notifications()->first();

    $this->actingAs(User::factory()->create())
        ->post(route('notifications.dismiss', $notification->id))
        ->assertNotFound();

    expect($notification->fresh()->read_at)->toBeNull();
});

it('requires sign-in to dismiss an alert', function () {
    $this->post(route('notifications.dismiss', 'anything'))->assertRedirect(route('login'));
});

it('clears the alert when a later backup of the same database succeeds', function () {
    $this->user->notify(new DatabaseBackupFailed($this->backup));

    $otherDatabase = ServerDatabase::factory()->create(['server_id' => $this->server->id]);
    $otherBackup = Backup::factory()->create([
        'server_database_id' => $otherDatabase->id,
        'storage_provider_id' => $this->backup->storage_provider_id,
        'user_id' => $this->user->id,
    ]);
    $this->user->notify(new DatabaseBackupFailed($otherBackup));

    $driver = Mockery::mock(CloudflareR2Driver::class);
    $driver->shouldReceive('validateCredentials')->andReturn(true);
    $storageManager = Mockery::mock(StorageProviderManager::class);
    $storageManager->shouldReceive('forAccount')->andReturn($driver);
    app()->instance(StorageProviderManager::class, $storageManager);

    $action = Mockery::mock(RunDatabaseBackup::class);
    $action->shouldReceive('execute');
    app()->instance(RunDatabaseBackup::class, $action);

    $nextBackup = Backup::factory()->create([
        'server_database_id' => $this->serverDatabase->id,
        'storage_provider_id' => $this->backup->storage_provider_id,
        'user_id' => $this->user->id,
    ]);

    app()->call([new RunBackupJob($nextBackup), 'handle']);

    expect($this->user->unreadNotifications()->get())
        ->toHaveCount(1)
        ->first()->data->target->toBe("server_database:{$otherDatabase->id}");
});

it('shares no alerts when nothing has failed', function () {
    $this->actingAs($this->user)
        ->get("/{$this->team->slug}/dashboard")
        ->assertInertia(fn (AssertableInertia $page) => $page->where('alerts', []));
});
