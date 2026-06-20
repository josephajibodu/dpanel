<?php

namespace App\Actions\Servers;

use App\Enums\ServerStatus;
use App\Events\ServerStatusChanged;
use App\Jobs\DeleteServerJob;
use App\Models\Server;

class DeleteServerAction
{
    public function execute(Server $server): void
    {
        $previousStatus = $server->status;

        $server->update(['status' => ServerStatus::Deleting]);

        event(new ServerStatusChanged($server, $previousStatus));

        DeleteServerJob::dispatch($server);
    }
}
