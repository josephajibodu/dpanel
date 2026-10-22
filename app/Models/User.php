<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\TeamPermission;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'git_name',
        'git_email',
        'current_team_id',
    ];

    /**
     * Team roles already looked up on this instance, keyed by team id.
     *
     * @var array<int, TeamRole|null>
     */
    protected array $resolvedTeamRoles = [];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    // Team relationships

    public function currentTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'current_team_id');
    }

    /** @return BelongsToMany<Team, $this> */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class)
            ->using(Membership::class)
            ->withPivot('team_role_id')
            ->withTimestamps();
    }

    public function ownedTeams(): HasMany
    {
        return $this->hasMany(Team::class);
    }

    public function belongsToTeam(Team $team): bool
    {
        return $this->teams()->where('team_id', $team->id)->exists()
            || $this->ownsTeam($team);
    }

    public function ownsTeam(Team $team): bool
    {
        return $this->id === $team->user_id;
    }

    /**
     * Whether this user may do the given thing in the given team. The team's
     * creator can always do everything; everyone else gets what their
     * membership's role grants.
     */
    public function hasTeamPermission(Team $team, TeamPermission $permission): bool
    {
        if ($this->ownsTeam($team)) {
            return true;
        }

        return $this->teamRole($team)?->grants($permission) ?? false;
    }

    /**
     * The role this user holds in the given team, or null if they are not a
     * member. Looked up once per team and remembered on this instance, so a
     * page that checks many permissions only queries once.
     */
    public function teamRole(Team $team): ?TeamRole
    {
        if (! array_key_exists($team->id, $this->resolvedTeamRoles)) {
            $this->resolvedTeamRoles[$team->id] = Membership::query()
                ->with('teamRole')
                ->where('team_id', $team->id)
                ->where('user_id', $this->id)
                ->first()
                ?->teamRole;
        }

        return $this->resolvedTeamRoles[$team->id];
    }

    public function switchTeam(Team $team): void
    {
        $this->forceFill(['current_team_id' => $team->id])->save();
        $this->setRelation('currentTeam', $team);
    }

    public function currentTeamOrFail(): Team
    {
        return $this->currentTeam ?? abort(403, 'No team selected.');
    }

    // Personal resource relationships (user-scoped, not team-scoped)

    public function sshKeys(): HasMany
    {
        return $this->hasMany(SshKey::class);
    }

    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class);
    }

    public function sourceControlAccounts(): HasMany
    {
        return $this->hasMany(SourceControlAccount::class);
    }
}
