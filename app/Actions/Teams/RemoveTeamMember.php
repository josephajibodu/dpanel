<?php

namespace App\Actions\Teams;

use App\Jobs\RevokeSshKeyJob;
use App\Models\SshKey;
use App\Models\Team;
use App\Models\User;

class RemoveTeamMember
{
    public function execute(Team $team, User $member): void
    {
        $team->users()->detach($member);

        $this->revokeSshKeysFromTeamServers($team, $member);

        // If this was their current team, switch them to their personal team
        if ($member->current_team_id === $team->id) {
            $personalTeam = $member->ownedTeams()->where('personal_team', true)->first();

            if ($personalTeam) {
                $member->switchTeam($personalTeam);
            }
        }
    }

    /**
     * Pull the member's SSH keys off every server of this team, so leaving the
     * team also ends their direct SSH access. Keys on other teams' servers stay.
     */
    private function revokeSshKeysFromTeamServers(Team $team, User $member): void
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
