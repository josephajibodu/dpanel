<?php

namespace App\Actions\Servers;

use App\Enums\ServerStatus;
use App\Jobs\DeleteServerJob;
use App\Models\Server;

class DeleteServerAction
{
    public function execute(Server $server): void
    {
        // Mark server as deleting
        $server->update([
            'status' => ServerStatus::Deleting,
        ]);

        // Dispatch deletion job
        DeleteServerJob::dispatch($server);
    }
}
