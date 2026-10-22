<?php

namespace App\Actions\Teams;

use App\Jobs\RevokeSshKeyJob;
use App\Models\SshKey;
use App\Models\Team;
use App\Models\User;

/**
 * Pull a member's SSH keys off every server of a team, ending their direct SSH
 * access there. Keys on other teams' servers stay.
 */
class RevokeMemberServerAccess
{
    public function execute(Team $team, User $member): void
    {
        $member->sshKeys()
            ->with(['servers' => fn ($query) => $query->where('servers.team_id', $team->id)])
            ->get()
            ->each(function (SshKey $sshKey): void {
                foreach ($sshKey->servers as $server) {
                    $sshKey->servers()->updateExistingPivot($server->id, ['status' => 'revoking']);

                    RevokeSshKeyJob::dispatch($sshKey, $server);
                }
            });
    }
}
