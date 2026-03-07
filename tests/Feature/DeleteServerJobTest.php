<?php

use App\Events\ServerDeleted;
use App\Jobs\DeleteServerJob;
use App\Models\Server;
use App\Services\Providers\ProviderManager;
use App\Services\SourceControlService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

it('broadcasts ServerDeleted when job completes successfully', function () {
    Event::fake([ServerDeleted::class]);

    $server = Server::factory()->create([
        'provider_server_id' => null,
        'meta' => [],
    ]);

    $mockProvider = \Mockery::mock(\App\Contracts\ProviderContract::class)->makePartial();
    $mockProvider->shouldReceive('deleteServer')->andReturn(null);
    $mockProvider->shouldReceive('deleteSshKey')->andReturn(null);

    $mockProviderManager = \Mockery::mock(ProviderManager::class);
    $mockProviderManager->shouldReceive('forAccount')
        ->with(\Mockery::on(fn ($account) => $account->id === $server->providerAccount->id))
        ->andReturn($mockProvider);

    $mockSourceControl = \Mockery::mock(SourceControlService::class);
    $mockSourceControl->shouldReceive('deleteAllAccountSshKeysForServer')
        ->with(\Mockery::on(fn ($s) => $s->id === $server->id))
        ->andReturn(null);

    $this->app->instance(ProviderManager::class, $mockProviderManager);
    $this->app->instance(SourceControlService::class, $mockSourceControl);

    $job = new DeleteServerJob($server);
    $job->handle(app(ProviderManager::class), app(SourceControlService::class));

    Event::assertDispatched(ServerDeleted::class, function (ServerDeleted $event) use ($server) {
        return $event->serverId === $server->id
            && $event->userId === $server->user_id;
    });

    $this->assertDatabaseMissing('servers', ['id' => $server->id]);
});
