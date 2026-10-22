<?php

use App\Enums\ServerStatus;
use App\Jobs\ControlWorkerJob;
use App\Jobs\CreateWorkerJob;
use App\Jobs\DestroyWorkerJob;
use App\Jobs\UpdateWorkerJob;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Models\Worker;
use App\Services\Ssh\SshConnection;
use App\Services\Ssh\SshService;
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

it('stores a new worker and dispatches job', function () {
    Queue::fake();

    $response = $this->actingAs($this->user)
        ->post("/{$this->team->slug}/servers/{$this->server->id}/workers", [
            'name' => 'queue-worker',
            'command' => 'php artisan queue:work',
            'site_id' => null,
            'user' => 'artisan',
            'numprocs' => 2,
            'auto_start' => true,
            'auto_restart' => true,
        ]);

    $response->assertRedirect();

    $this->assertDatabaseHas('workers', [
        'server_id' => $this->server->id,
        'name' => 'queue-worker',
        'command' => 'php artisan queue:work',
        'user' => 'artisan',
        'numprocs' => 2,
        'auto_start' => true,
        'auto_restart' => true,
        'status' => 'pending',
    ]);

    Queue::assertPushed(CreateWorkerJob::class);
});

it('validates worker name and command are required', function () {
    $response = $this->actingAs($this->user)
        ->post("/{$this->team->slug}/servers/{$this->server->id}/workers", [
            'name' => '',
            'command' => '',
            'user' => 'artisan',
            'numprocs' => 1,
        ]);

    $response->assertSessionHasErrors(['name', 'command']);
    $this->assertDatabaseCount('workers', 0);
});

it('validates worker site_id belongs to server', function () {
    $otherServer = Server::factory()->forTeam($this->team)->create();
    $site = \App\Models\Site::factory()->create([
        'server_id' => $otherServer->id,
    ]);

    $response = $this->actingAs($this->user)
        ->post("/{$this->team->slug}/servers/{$this->server->id}/workers", [
            'name' => 'worker',
            'command' => 'php artisan queue:work',
            'site_id' => $site->id,
            'user' => 'artisan',
            'numprocs' => 1,
        ]);

    $response->assertSessionHasErrors('site_id');
    $this->assertDatabaseCount('workers', 0);
});

it('updates a worker and dispatches job', function () {
    Queue::fake();

    $worker = Worker::factory()->create([
        'server_id' => $this->server->id,
        'name' => 'old-name',
        'command' => 'old command',
        'numprocs' => 1,
    ]);

    $response = $this->actingAs($this->user)
        ->put("/{$this->team->slug}/servers/{$this->server->id}/workers/{$worker->id}", [
            'name' => 'new-name',
            'command' => 'php artisan queue:work --sleep=3',
            'site_id' => null,
            'user' => 'artisan',
            'numprocs' => 4,
            'auto_start' => false,
            'auto_restart' => true,
        ]);

    $response->assertRedirect();

    $worker->refresh();
    expect($worker->name)->toBe('new-name')
        ->and($worker->command)->toBe('php artisan queue:work --sleep=3')
        ->and($worker->user)->toBe('artisan')
        ->and($worker->numprocs)->toBe(4)
        ->and($worker->auto_start)->toBeFalse();

    Queue::assertPushed(UpdateWorkerJob::class);
});

it('dispatches job to destroy a worker', function () {
    Queue::fake();

    $worker = Worker::factory()->create([
        'server_id' => $this->server->id,
        'name' => 'to_delete',
    ]);

    $response = $this->actingAs($this->user)
        ->delete("/{$this->team->slug}/servers/{$this->server->id}/workers/{$worker->id}");

    $response->assertRedirect();
    Queue::assertPushed(DestroyWorkerJob::class);
    $this->assertDatabaseHas('workers', ['id' => $worker->id]);
});

it('starts a worker and marks it starting immediately', function () {
    Queue::fake();

    $worker = Worker::factory()->create([
        'server_id' => $this->server->id,
        'status' => 'stopped',
    ]);

    $response = $this->actingAs($this->user)
        ->post("/{$this->team->slug}/servers/{$this->server->id}/workers/{$worker->id}/start");

    $response->assertRedirect();
    Queue::assertPushed(ControlWorkerJob::class);
    $this->assertDatabaseHas('workers', [
        'id' => $worker->id,
        'status' => 'starting',
    ]);
});

it('stops a worker and marks it stopping immediately', function () {
    Queue::fake();

    $worker = Worker::factory()->create([
        'server_id' => $this->server->id,
        'status' => 'active',
    ]);

    $response = $this->actingAs($this->user)
        ->post("/{$this->team->slug}/servers/{$this->server->id}/workers/{$worker->id}/stop");

    $response->assertRedirect();
    Queue::assertPushed(ControlWorkerJob::class);
    $this->assertDatabaseHas('workers', [
        'id' => $worker->id,
        'status' => 'stopping',
    ]);
});

it('restarts a worker and marks it restarting immediately', function () {
    Queue::fake();

    $worker = Worker::factory()->create([
        'server_id' => $this->server->id,
        'status' => 'stopped',
    ]);

    $response = $this->actingAs($this->user)
        ->post("/{$this->team->slug}/servers/{$this->server->id}/workers/{$worker->id}/restart");

    $response->assertRedirect();
    Queue::assertPushed(ControlWorkerJob::class);
    $this->assertDatabaseHas('workers', [
        'id' => $worker->id,
        'status' => 'restarting',
    ]);
});

it('returns worker logs from the log file FlitOps chose', function () {
    $worker = Worker::factory()->create(['server_id' => $this->server->id]);

    $connection = Mockery::mock(SshConnection::class);
    $connection->shouldReceive('download')
        ->once()
        ->with('/var/log/supervisor/flitops-worker-'.$worker->id.'.log')
        ->andReturn('processed job 1');
    $connection->shouldReceive('disconnect');

    $sshService = Mockery::mock(SshService::class);
    $sshService->shouldReceive('connect')->andReturn($connection);
    $this->app->instance(SshService::class, $sshService);

    $response = $this->actingAs($this->user)
        ->get("/{$this->team->slug}/servers/{$this->server->id}/workers/{$worker->id}/logs");

    $response->assertOk();
    $response->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
    expect($response->getContent())->toBe('processed job 1');
});

it('denies access to worker from another server', function () {
    $otherServer = Server::factory()->forTeam($this->team)->create();
    $worker = Worker::factory()->create(['server_id' => $otherServer->id]);

    $response = $this->actingAs($this->user)
        ->delete("/{$this->team->slug}/servers/{$this->server->id}/workers/{$worker->id}");

    $response->assertNotFound();
    $this->assertDatabaseHas('workers', ['id' => $worker->id]);
});

// --- Values written into the supervisor file ---

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function workerPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'queue-worker',
        'command' => 'php artisan queue:work',
        'site_id' => null,
        'user' => 'artisan',
        'numprocs' => 1,
    ], $overrides);
}

it('accepts a worker that runs as the server user', function () {
    Queue::fake();

    $this->actingAs($this->user)
        ->post("/{$this->team->slug}/servers/{$this->server->id}/workers", workerPayload())
        ->assertSessionHasNoErrors();

    $this->assertDatabaseHas('workers', ['user' => 'artisan']);
});

it('ignores a log file path sent by the browser', function () {
    Queue::fake();

    $this->actingAs($this->user)
        ->post("/{$this->team->slug}/servers/{$this->server->id}/workers", workerPayload(['stdout_logfile' => '/etc/cron.d/evil']))
        ->assertSessionHasNoErrors();

    $worker = Worker::firstOrFail();

    expect($worker->supervisorConfig('w'))
        ->toContain('stdout_logfile=/var/log/supervisor/flitops-worker-'.$worker->id.'.log')
        ->not->toContain('/etc/cron.d/evil');
});

it('rejects worker users other than the server user', function (string $method, string $user) {
    Queue::fake();

    $worker = Worker::factory()->create(['server_id' => $this->server->id]);
    $url = $method === 'post'
        ? "/{$this->team->slug}/servers/{$this->server->id}/workers"
        : "/{$this->team->slug}/servers/{$this->server->id}/workers/{$worker->id}";

    $this->actingAs($this->user)
        ->{$method}($url, workerPayload(['user' => $user]))
        ->assertSessionHasErrors('user');
})->with(['post', 'put'])->with(['root', 'www-data', 'deploy', 'artisan2']);

it('rejects line breaks and control characters in worker fields', function (string $method, string $field, string $value) {
    Queue::fake();

    $worker = Worker::factory()->create(['server_id' => $this->server->id]);
    $url = $method === 'post'
        ? "/{$this->team->slug}/servers/{$this->server->id}/workers"
        : "/{$this->team->slug}/servers/{$this->server->id}/workers/{$worker->id}";

    $this->actingAs($this->user)
        ->{$method}($url, workerPayload([$field => $value]))
        ->assertSessionHasErrors($field);
})->with(['post', 'put'])->with([
    'newline in command' => ['command', "php artisan queue:work\nuser=root"],
    'carriage return in command' => ['command', "php artisan queue:work\ruser=root"],
    'null byte in command' => ['command', "php artisan\0queue:work"],
    'newline in user' => ['user', "artisan\nuser=root"],
]);
