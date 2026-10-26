<?php

namespace Database\Factories;

use App\Models\Server;
use App\Models\TerminalSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TerminalSession>
 */
class TerminalSessionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'server_id' => Server::factory(),
            'user_id' => User::factory(),
            'token_hash' => TerminalSession::hashToken(Str::random(64)),
            'ip_address' => fake()->ipv4(),
            'expires_at' => now()->addSeconds(TerminalSession::TOKEN_TTL_SECONDS),
        ];
    }

    public function started(): static
    {
        return $this->state(fn (array $attributes) => [
            'started_at' => now(),
        ]);
    }

    public function ended(): static
    {
        return $this->state(fn (array $attributes) => [
            'started_at' => now()->subMinutes(10),
            'ended_at' => now(),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => now()->subSecond(),
        ]);
    }
}
