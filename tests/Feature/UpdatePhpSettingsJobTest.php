<?php

use App\Actions\ServerPhp\UpdatePhpSettings;
use App\Enums\ConnectionStatus;
use App\Enums\ServiceType;
use App\Events\ServerPhpUpdated;
use App\Jobs\UpdatePhpSettingsJob;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = Team::factory()->forUser($this->user)->create();
    $this->server = Server::factory()->forTeam($this->team)->create([
        'status' => \App\Enums\ServerStatus::Active,
        'connection_status' => ConnectionStatus::Successful,
        'php_version' => '8.3',
    ]);
    $this->phpService = $this->server->createService(ServiceType::Php, '8.3', true);
    $this->phpService->update([
        'type_data' => [
            'settings' => ['upload_max_filesize' => '64M', 'memory_limit' => '256M'],
            'settings_sync_status' => 'pending',
            'settings_sync_error' => null,
        ],
    ]);
});

it('sets syncing status before action and synced status after success', function () {
    Event::fake([ServerPhpUpdated::class]);

    $action = Mockery::mock(UpdatePhpSettings::class);
    $action->shouldReceive('execute')->once();
    $this->app->instance(UpdatePhpSettings::class, $action);

    (new UpdatePhpSettingsJob($this->phpService))->handle($action);

    $this->phpService->refresh();
    expect($this->phpService->type_data['settings_sync_status'])->toBe('synced');
    expect($this->phpService->type_data['settings_sync_error'])->toBeNull();

    Event::assertDispatched(ServerPhpUpdated::class, 2);
});

it('sets failed status and re-throws when action throws', function () {
    Event::fake([ServerPhpUpdated::class]);

    $action = Mockery::mock(UpdatePhpSettings::class);
    $action->shouldReceive('execute')->once()->andThrow(new RuntimeException('SSH timeout'));
    $this->app->instance(UpdatePhpSettings::class, $action);

    expect(fn () => (new UpdatePhpSettingsJob($this->phpService))->handle($action))
        ->toThrow(RuntimeException::class);

    $this->phpService->refresh();
    expect($this->phpService->type_data['settings_sync_status'])->toBe('failed');
    expect($this->phpService->type_data['settings_sync_error'])->toBe('SSH timeout');

    Event::assertDispatched(ServerPhpUpdated::class, 2);
});

it('passes the settings stored in type_data to the action', function () {
    Event::fake([ServerPhpUpdated::class]);

    $capturedSettings = null;
    $action = Mockery::mock(UpdatePhpSettings::class);
    $action->shouldReceive('execute')
        ->once()
        ->withArgs(function ($server, $settings) use (&$capturedSettings) {
            $capturedSettings = $settings;

            return true;
        });
    $this->app->instance(UpdatePhpSettings::class, $action);

    (new UpdatePhpSettingsJob($this->phpService))->handle($action);

    expect($capturedSettings)->toBe(['upload_max_filesize' => '64M', 'memory_limit' => '256M']);
});
