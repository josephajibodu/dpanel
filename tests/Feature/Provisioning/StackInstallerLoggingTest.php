<?php

use App\Enums\ProvisioningStep;
use App\Enums\ServerStatus;
use App\Events\ProvisioningOutput;
use App\Models\Server;
use App\Services\Provisioning\DatabaseService;
use App\Services\Provisioning\FinalTouchesService;
use App\Services\Provisioning\NginxService;
use App\Services\Provisioning\PhpService;
use App\Services\Provisioning\RedisService;
use App\Services\Provisioning\StackInstaller;
use App\Services\Provisioning\SystemService;
use App\Services\Ssh\SshConnection;
use Illuminate\Support\Facades\Event;

it('persists a provisioning transcript and broadcasts each line', function () {
    Event::fake([ProvisioningOutput::class]);

    /** @var Server $server */
    $server = Server::factory()->create([
        'status' => ServerStatus::Provisioning,
        'provisioning_step' => ProvisioningStep::WaitingForServer,
    ]);

    $connection = Mockery::mock(SshConnection::class);
    $connection->shouldIgnoreMissing();

    foreach ([SystemService::class, PhpService::class, NginxService::class, DatabaseService::class, RedisService::class, FinalTouchesService::class] as $service) {
        $this->mock($service)->shouldIgnoreMissing();
    }

    app(StackInstaller::class)->install($server, $connection);

    expect($server->provisioningLogs()->count())->toBeGreaterThan(0)
        ->and($server->provisioningLogs()->where('type', 'info')->pluck('message')->all())->toContain(
            ProvisioningStep::PreparingServer->label(),
            ProvisioningStep::ConfiguringSwap->label(),
            ProvisioningStep::InstallingBaseDependencies->label(),
            ProvisioningStep::MakingFinalTouches->label(),
        )
        ->and($server->provisioningLogs()->where('type', 'success')->pluck('message')->all())
        ->toContain(ProvisioningStep::Finished->label());

    Event::assertDispatched(ProvisioningOutput::class, fn (ProvisioningOutput $event) => $event->server->is($server)
        && $event->type === 'success'
        && $event->line === ProvisioningStep::Finished->label());
});
