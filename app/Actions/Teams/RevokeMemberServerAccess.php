<?php

namespace App\Actions\Teams;

use App\Jobs\RevokeSshKeyJob;
use App\Models\ServerSshKey;
use App\Models\Team;
use App\Models\User;

/**
 * Pull a member's SSH keys off a team's servers, ending their direct SSH
 * access there. Keys on other teams' servers stay.
 */
class RevokeMemberServerAccess
{
    /**
     * @param  bool  $serverUserOnly  Only take keys off the server user (full server access), leaving keys on isolated sites' own users.
     */
    public function execute(Team $team, User $member, bool $serverUserOnly = false): void
    {
        $placements = ServerSshKey::query()
            ->whereIn('ssh_key_id', $member->sshKeys()->select('id'))
            ->whereIn('server_id', $team->servers()->select('id'))
            ->when($serverUserOnly, fn ($query) => $query->where('system_user', config('server.user')))
            ->get();

        $sshKeys = $member->sshKeys()->get()->keyBy('id');
        $servers = $team->servers()->get()->keyBy('id');

        foreach ($placements as $placement) {
            $placement->update(['status' => 'revoking']);

            RevokeSshKeyJob::dispatch($sshKeys[$placement->ssh_key_id], $servers[$placement->server_id], $placement->system_user);
        }
    }
}
