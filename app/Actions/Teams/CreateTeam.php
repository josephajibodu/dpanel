<?php

namespace App\Actions\Teams;

use App\Enums\TeamRoleKey;
use App\Models\Team;
use App\Models\TeamRole;
use App\Models\User;
use Illuminate\Support\Str;

class CreateTeam
{
    public function execute(User $user, string $name, bool $personalTeam = false, ?string $slug = null): Team
    {
        $team = Team::create([
            'user_id' => $user->id,
            'name' => $name,
            'slug' => $slug ?? $this->generateSlug($name),
            'personal_team' => $personalTeam,
        ]);

        $team->users()->attach($user, ['team_role_id' => TeamRole::builtIn(TeamRoleKey::Owner)->id]);

        return $team;
    }

    private function generateSlug(string $name): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $i = 2;

        while (Team::where('slug', $slug)->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }
}
