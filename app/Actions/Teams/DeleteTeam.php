<?php

namespace App\Actions\Teams;

use App\Models\Backup;
use App\Models\BackupSchedule;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DeleteTeam
{
    /**
     * Delete the team and everything FleetOps stores for it, as one step: if
     * any part fails, nothing is deleted and the user stays on this team.
     *
     * Backup records and schedules are removed first. A storage provider
     * can't be deleted while backups still point at it (so deleting one on
     * its own can't orphan them), and that rule would otherwise block the
     * team's storage providers from being deleted with the team. Only the
     * records go: backup files already uploaded stay in the bucket.
     */
    public function execute(User $user, Team $team): void
    {
        DB::transaction(function () use ($user, $team): void {
            // Move the deleting owner to their personal team if they have one
            $personalTeam = $user->ownedTeams()
                ->where('personal_team', true)
                ->where('id', '!=', $team->id)
                ->first();

            if ($personalTeam) {
                $user->switchTeam($personalTeam);
            }

            $storageProviderIds = $team->storageProviders()->select('id');

            Backup::query()->whereIn('storage_provider_id', $storageProviderIds)->delete();
            BackupSchedule::query()->whereIn('storage_provider_id', $storageProviderIds)->delete();

            $team->delete();
        });
    }
}
