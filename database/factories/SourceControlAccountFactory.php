<?php

namespace Database\Factories;

use App\Enums\RepositoryProvider;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SourceControlAccount>
 */
class SourceControlAccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $username = fake()->userName();

        return [
            'user_id' => User::factory(),
            'provider' => RepositoryProvider::Github,
            'provider_user_id' => (string) fake()->randomNumber(6),
            'provider_username' => $username,
            'name' => fake()->name(),
            'email' => fake()->safeEmail(),
            'avatar_url' => 'https://github.com/'.$username.'.png',
            'token' => 'gho_'.fake()->sha1(),
            'refresh_token' => null,
            'token_expires_at' => null,
            'connected_at' => now(),
        ];
    }

    public function forUser(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user->id,
        ]);
    }

    public function github(): static
    {
        return $this->state(fn (array $attributes) => [
            'provider' => RepositoryProvider::Github,
        ]);
    }

    public function gitlab(): static
    {
        return $this->state(fn (array $attributes) => [
            'provider' => RepositoryProvider::Gitlab,
        ]);
    }
}
