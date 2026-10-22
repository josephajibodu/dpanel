<?php

namespace App\Actions\SshKeys;

use App\Jobs\SyncSshKeyJob;
use App\Models\Server;
use App\Models\ServerSshKey;
use App\Models\Site;
use App\Models\SshKey;
use App\Models\Team;

class SyncSshKeyAction
{
    /**
     * Sync an SSH key to the server user of the given servers (full access)
     * and to the own user of the given isolated sites (that site only). Only
     * active servers belonging to the given team are considered, so a key can
     * never reach a server outside the team the request was made in.
     *
     * @param  array<int>  $serverIds
     * @param  array<int>  $siteIds
     */
    public function execute(Team $team, SshKey $sshKey, array $serverIds, array $siteIds = []): void
    {
        $servers = $team->servers()
            ->whereIn('id', $serverIds)
            ->where('status', 'active')
            ->get();

        foreach ($servers as $server) {
            $this->place($sshKey, $server, null);
        }

        $sites = Site::query()
            ->whereIn('id', $siteIds)
            ->whereNotNull('system_user')
            ->whereHas('server', fn ($query) => $query->where('team_id', $team->id)->where('status', 'active'))
            ->with('server')
            ->get();

        foreach ($sites as $site) {
            $this->place($sshKey, $site->server, $site);
        }
    }

    private function place(SshKey $sshKey, Server $server, ?Site $site): void
    {
        ServerSshKey::query()->updateOrCreate(
            [
                'ssh_key_id' => $sshKey->id,
                'server_id' => $server->id,
                'system_user' => $site?->system_user ?? config('server.user'),
            ],
            [
                'site_id' => $site?->id,
                'status' => 'pending',
                'synced_at' => null,
            ],
        );

        SyncSshKeyJob::dispatch($sshKey, $server, $site);
    }
}
