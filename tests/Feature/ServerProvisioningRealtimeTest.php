<?php

use App\Enums\ProvisioningStep;
use App\Enums\ServerStatus;
use App\Events\ServerStatusChanged;
use App\Http\Resources\ServerResource;
use App\Models\Server;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;

it('includes provisioning step metadata in server resource', function () {
    $server = Server::factory()->provisioning()->create([
        'provisioning_step' => ProvisioningStep::InstallingPhp,
    ]);

    $payload = ServerResource::make($server)->toArray(Request::create('/'));

    expect($payload)->toHaveKeys([
        'provisioning_step',
        'provisioning_steps',
    ]);

    expect($payload['provisioning_step'])->toBe([
        'value' => ProvisioningStep::InstallingPhp->value,
        'label' => ProvisioningStep::InstallingPhp->label(),
        'description' => ProvisioningStep::InstallingPhp->description(),
    ]);

    expect($payload['provisioning_steps'])->not->toBeEmpty()
        ->and($payload['provisioning_steps'][0])->toHaveKeys(['value', 'label', 'description']);
});

it('dispatches server status changed event when provisioning step changes', function () {
    Event::fake([ServerStatusChanged::class]);

    $server = Server::factory()->provisioning()->create([
        'status' => ServerStatus::Provisioning,
        'provisioning_step' => ProvisioningStep::PreparingServer,
    ]);

    $server->update([
        'provisioning_step' => ProvisioningStep::ConfiguringSwap,
    ]);

    Event::assertDispatched(ServerStatusChanged::class, function (ServerStatusChanged $event) use ($server) {
        return $event->server->is($server)
            && $event->previousStatus === ServerStatus::Provisioning
            && $event->previousProvisioningStep === ProvisioningStep::PreparingServer
            && $event->server->provisioning_step === ProvisioningStep::ConfiguringSwap;
    });
});

it('dispatches server status changed event when status changes', function () {
    Event::fake([ServerStatusChanged::class]);

    $server = Server::factory()->pending()->create([
        'status' => ServerStatus::Pending,
        'provisioning_step' => ProvisioningStep::Pending,
    ]);

    $server->update([
        'status' => ServerStatus::Creating,
    ]);

    Event::assertDispatched(ServerStatusChanged::class, function (ServerStatusChanged $event) use ($server) {
        return $event->server->is($server)
            && $event->previousStatus === ServerStatus::Pending
            && $event->server->status === ServerStatus::Creating;
    });
});
