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
        return [
            'user_id' => User::factory(),
            'name' => fake()->company().' Team',
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
        return $this->state(fn (array $attributes) => [
            'user_id' => $user->id,
            'name' => $user->name."'s Team",
            'personal_team' => true,
        ]);
    }
}
