<?php

namespace App\Models;

use App\Enums\Plan;
use App\Enums\PlanFeature;
use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Team extends Model
{
    /** @use HasFactory<TeamFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'slug',
        'personal_team',
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    protected function casts(): array
    {
        return [
            'personal_team' => 'boolean',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->using(Membership::class)
            ->withPivot('team_role_id')
            ->withTimestamps();
    }

    public function servers(): HasMany
    {
        return $this->hasMany(Server::class);
    }

    public function providerAccounts(): HasMany
    {
        return $this->hasMany(ProviderAccount::class);
    }

    public function storageProviders(): HasMany
    {
        return $this->hasMany(StorageProvider::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(TeamInvitation::class);
    }

    public function hasUser(User $user): bool
    {
        return $this->users()->where('user_id', $user->id)->exists()
            || $this->owner->is($user);
    }

    public function hasUserWithEmail(string $email): bool
    {
        return $this->users()->where('email', $email)->exists();
    }

    public function userIsOwner(User $user): bool
    {
        return $this->user_id === $user->id;
    }

    /**
     * The plan this team runs on, which is always its owner's.
     */
    public function plan(): Plan
    {
        return $this->owner->plan;
    }

    public function planIncludes(PlanFeature $feature): bool
    {
        return $this->plan()->includes($feature);
    }

    /**
     * Whether the owner's plan leaves room for one more server in this team.
     */
    public function hasRoomForAnotherServer(): bool
    {
        return $this->owner->hasRoomForAnotherServer();
    }
}
