<?php

namespace App\Actions\SshKeys;

use App\Jobs\SyncSshKeyJob;
use App\Models\SshKey;
use App\Models\Team;

class SyncSshKeyAction
{
    /**
     * Sync an SSH key to the specified servers. Only active servers belonging to
     * the given team are considered, so a key can never reach a server outside
     * the team the request was made in.
     *
     * @param  array<int>  $serverIds
     */
    public function execute(Team $team, SshKey $sshKey, array $serverIds): void
    {
        $servers = $team->servers()
            ->whereIn('id', $serverIds)
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
