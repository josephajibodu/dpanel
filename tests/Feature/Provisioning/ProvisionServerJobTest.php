<?php

use App\Enums\ServerStatus;
use App\Jobs\ProvisionServerJob;
use App\Models\Server;
use App\Notifications\ServerProvisioningFailed;
use Illuminate\Support\Facades\Notification;

it('stores the error message and sends a notification when the job fails', function () {
    Notification::fake();

    $server = Server::factory()->pending()->create();

    $exception = new \RuntimeException('Provider API returned 503');

    $job = new ProvisionServerJob($server);
    $job->failed($exception);

    $server->refresh();

    expect($server->status)->toBe(ServerStatus::Error)
        ->and($server->error_message)->toBe('Provider API returned 503');

    Notification::assertSentTo(
        $server->user,
        ServerProvisioningFailed::class,
        fn (ServerProvisioningFailed $notification) => $notification->server->is($server)
            && $notification->errorMessage === 'Provider API returned 503'
    );
});
