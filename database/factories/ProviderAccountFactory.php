<?php

namespace Database\Factories;

use App\Enums\Provider;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ProviderAccount>
 */
class ProviderAccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'user_id' => User::factory(),
            'provider' => fake()->randomElement(Provider::cases()),
            'name' => fake()->company().' Account',
            'credentials' => ['api_token' => fake()->sha256()],
            'is_valid' => true,
            'validated_at' => now(),
        ];
    }

    public function forTeam(Team $team): static
    {
        return $this->state(fn (array $attributes) => [
            'team_id' => $team->id,
            'user_id' => $team->user_id,
        ]);
    }

    public function forUser(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user->id,
        ]);
    }

    public function digitalocean(): static
    {
        return $this->state(fn (array $attributes) => [
            'provider' => Provider::DigitalOcean,
        ]);
    }

    public function hetzner(): static
    {
        return $this->state(fn (array $attributes) => [
            'provider' => Provider::Hetzner,
        ]);
    }

    public function vultr(): static
    {
        return $this->state(fn (array $attributes) => [
            'provider' => Provider::Vultr,
        ]);
    }

    public function invalid(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_valid' => false,
            'validated_at' => null,
        ]);
    }
}
