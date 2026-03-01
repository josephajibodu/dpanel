<?php

namespace App\Realtime;

use App\Models\User;

class RealtimeDiagnosticsChannelAuthorizer
{
    public function canAccess(User $user, int $requestedUserId): bool
    {
        return $user->id === $requestedUserId;
    }
}
