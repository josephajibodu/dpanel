<?php

namespace Database\Factories;

use App\Enums\ServerConnectionStatus;
use App\Models\Server;
use App\Models\ServerConnection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServerConnection>
 */
class ServerConnectionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'server_id' => Server::factory()->database(),
            'client_server_id' => Server::factory()->web(),
            'host_address' => '10.0.0.'.fake()->numberBetween(1, 127),
            'client_address' => '10.0.0.'.fake()->numberBetween(128, 254),
            'status' => ServerConnectionStatus::Active,
            'error_message' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ServerConnectionStatus::Pending,
        ]);
    }
}
