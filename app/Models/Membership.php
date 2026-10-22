<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * A user's membership of a team (a `team_user` row) and the role it carries.
 */
class Membership extends Pivot
{
    protected $table = 'team_user';

    public function teamRole(): BelongsTo
    {
        return $this->belongsTo(TeamRole::class);
    }
}
