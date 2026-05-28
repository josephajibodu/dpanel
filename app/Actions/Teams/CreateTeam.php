<?php

namespace App\Actions\Teams;

use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Str;

class CreateTeam
{
    public function execute(User $user, string $name, bool $personalTeam = false): Team
    {
        $team = Team::create([
            'user_id' => $user->id,
            'name' => $name,
            'slug' => $this->generateSlug($name),
            'personal_team' => $personalTeam,
        ]);

        $team->users()->attach($user, ['role' => 'owner']);

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
