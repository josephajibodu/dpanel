<?php

namespace App\Actions\Servers;

use App\Actions\Teams\RevokeMemberServerAccess;
use App\Enums\ActivityEvent;
use App\Enums\TeamPermission;
use App\Events\ServerDeleted;
use App\Models\ActivityLog;
use App\Models\Backup;
use App\Models\BackupSchedule;
use App\Models\ProviderAccount;
use App\Models\Server;
use App\Models\ServerSshKey;
use App\Models\SshKey;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class TransferServerAction
{
    public function __construct(private RevokeMemberServerAccess $revokeServerAccess) {}

    /**
     * Move the server, and everything on it, to another team.
     *
     * Sites, databases, workers and the rest hang off the server, so they
     * follow it. What stays behind is anything that belongs to the old team:
     * backup records and schedules go (they point at the old team's storage;
     * the files stay in the bucket), and SSH keys come off for people who
     * don't have server access in the new team. A cloud server is re-linked
     * to the new team's account for the same cloud account, so the old
     * team's API credentials stay with the old team.
     */
    public function execute(Server $server, Team $destination, User $transferredBy, ?ProviderAccount $providerAccount = null): void
    {
        $origin = $server->team;

        DB::transaction(function () use ($server, $origin, $destination, $transferredBy, $providerAccount): void {
            $databaseIds = $server->databases()->select('id');
            $siteIds = $server->sites()->select('id');

            foreach ([Backup::class, BackupSchedule::class] as $model) {
                $model::query()
                    ->where(fn ($query) => $query
                        ->whereIn('server_database_id', $databaseIds)
                        ->orWhereIn('site_id', $siteIds))
                    ->delete();
            }

            $server->terminalSessions()->whereNull('started_at')->update(['expires_at' => now()]);

            $server->update([
                'team_id' => $destination->id,
                'provider_account_id' => $providerAccount?->id,
            ]);
            $server->setRelation('team', $destination);

            $properties = ['server' => $server->name, 'from' => $origin->name, 'to' => $destination->name];

            ActivityLog::record($origin, ActivityEvent::ServerTransferred, $server, $properties, $transferredBy);
            ActivityLog::record($destination, ActivityEvent::ServerTransferred, $server, $properties, $transferredBy);
        });

        $this->revokeKeysOfPeopleWithoutAccess($server, $destination);

        event(new ServerDeleted(serverId: $server->id, teamId: $origin->id));
    }

    /**
     * Take SSH keys off the server for everyone whose role in the new team
     * wouldn't let them put those keys there.
     */
    private function revokeKeysOfPeopleWithoutAccess(Server $server, Team $destination): void
    {
        $keyOwners = User::query()
            ->whereIn('id', SshKey::query()
                ->whereIn('id', ServerSshKey::query()->where('server_id', $server->id)->select('ssh_key_id'))
                ->select('user_id'))
            ->get();

        foreach ($keyOwners as $keyOwner) {
            if (! $keyOwner->hasTeamPermission($destination, TeamPermission::AccessServers)) {
                $this->revokeServerAccess->execute($destination, $keyOwner, onlyServer: $server);
            } elseif (! $keyOwner->hasTeamPermission($destination, TeamPermission::AccessServersAsAdmin)) {
                $this->revokeServerAccess->execute($destination, $keyOwner, serverUserOnly: true, onlyServer: $server);
            }
        }
    }
}
