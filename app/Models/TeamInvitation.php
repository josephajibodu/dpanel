<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamInvitation extends Model
{
    protected $fillable = [
        'team_id',
        'email',
        'team_role_id',
        'token',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'token',
    ];

    /**
     * @var list<string>
     */
    protected $appends = [
        'role',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function teamRole(): BelongsTo
    {
        return $this->belongsTo(TeamRole::class);
    }

    /**
     * The invited role's key, which the invitation pages display.
     */
    protected function role(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->teamRole?->key);
    }
}
