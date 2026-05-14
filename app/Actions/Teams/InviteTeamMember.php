<?php

namespace App\Actions\Teams;

use App\Models\Team;
use App\Models\TeamInvitation;
use App\Notifications\TeamInvitationNotification;
use Illuminate\Support\Str;

class InviteTeamMember
{
    public function execute(Team $team, string $email, string $role = 'member'): TeamInvitation
    {
        $invitation = $team->invitations()->create([
            'email' => $email,
            'role' => $role,
            'token' => Str::uuid()->toString(),
        ]);

        $invitation->notify(new TeamInvitationNotification($invitation));

        return $invitation;
    }
}
