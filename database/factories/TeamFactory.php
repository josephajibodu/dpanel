<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Team>
 */
class TeamFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->company().' Team';

        return [
            'user_id' => User::factory(),
            'name' => $name,
            'slug' => \Illuminate\Support\Str::slug($name).'-'.fake()->unique()->randomNumber(4),
            'personal_team' => false,
        ];
    }

    public function personal(): static
    {
        return $this->state(fn (array $attributes) => [
            'personal_team' => true,
        ]);
    }

    public function forUser(User $user): static
    {
        $name = $user->name."'s Team";

        return $this->state(fn (array $attributes) => [
            'user_id' => $user->id,
            'name' => $name,
            'slug' => \Illuminate\Support\Str::slug($name).'-'.fake()->unique()->randomNumber(4),
            'personal_team' => true,
        ]);
    }
}
