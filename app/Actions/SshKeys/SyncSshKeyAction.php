<?php

namespace App\Actions\SshKeys;

use App\Jobs\SyncSshKeyJob;
use App\Models\Server;
use App\Models\SshKey;

class SyncSshKeyAction
{
    /**
     * Sync an SSH key to the specified servers.
     *
     * @param  array<int>  $serverIds
     */
    public function execute(SshKey $sshKey, array $serverIds): void
    {
        $servers = Server::whereIn('id', $serverIds)
            ->where('user_id', $sshKey->user_id)
            ->where('status', 'active')
            ->get();

        foreach ($servers as $server) {
            // Create or update pivot record with pending status
            $sshKey->servers()->syncWithoutDetaching([
                $server->id => [
                    'status' => 'pending',
                    'synced_at' => null,
                ],
            ]);

            // Dispatch sync job
            SyncSshKeyJob::dispatch($sshKey, $server);
        }
    }
}
