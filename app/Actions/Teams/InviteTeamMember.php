<?php

namespace App\Actions\Teams;

use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\TeamRole;
use App\Notifications\TeamInvitationNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class InviteTeamMember
{
    public function execute(Team $team, string $email, TeamRole $role): TeamInvitation
    {
        $invitation = $team->invitations()->create([
            'email' => $email,
            'team_role_id' => $role->id,
            'token' => Str::uuid()->toString(),
        ]);

        Notification::route('mail', $invitation->email)
            ->notify(new TeamInvitationNotification($invitation));

        return $invitation;
    }
}
