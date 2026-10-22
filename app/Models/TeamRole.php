<?php

namespace App\Models;

use App\Enums\TeamPermission;
use App\Enums\TeamRoleKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A role a team member can hold. Built-in roles have a NULL team_id and take
 * their permissions from {@see TeamRoleKey::permissions()}; a role with a
 * team_id is reserved for team-specific custom roles.
 */
class TeamRole extends Model
{
    protected $fillable = [
        'team_id',
        'key',
        'name',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public static function builtIn(TeamRoleKey $roleKey): self
    {
        return static::query()->builtIn()->where('key', $roleKey->value)->firstOrFail();
    }

    /**
     * @param  Builder<TeamRole>  $query
     */
    public function scopeBuiltIn(Builder $query): void
    {
        $query->whereNull('team_id');
    }

    public function isBuiltIn(): bool
    {
        return $this->team_id === null;
    }

    /**
     * Custom roles grant nothing until they get their own permission rows.
     *
     * @return list<TeamPermission>
     */
    public function permissions(): array
    {
        if (! $this->isBuiltIn()) {
            return [];
        }

        return TeamRoleKey::tryFrom($this->key)?->permissions() ?? [];
    }

    public function grants(TeamPermission $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }
}
