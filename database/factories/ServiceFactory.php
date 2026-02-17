<?php

namespace Database\Factories;

use App\Models\Server;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Service>
 */
class ServiceFactory extends Factory
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
            'type' => fake()->randomElement(['php', 'nginx', 'redis', 'database', 'supervisor']),
            'type_data' => null,
            'name' => null,
            'version' => '8.3',
            'installed_version' => null,
            'unit' => 'nginx',
            'logs' => null,
            'status' => 'ready',
            'is_default' => false,
        ];
    }

    public function php(string $version = '8.3'): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'php',
            'version' => $version,
            'installed_version' => $version,
            'unit' => "php{$version}-fpm",
            'status' => 'ready',
        ]);
    }

    public function nginx(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'nginx',
            'unit' => 'nginx',
            'status' => 'ready',
        ]);
    }

    public function redis(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'redis',
            'unit' => 'redis-server',
            'status' => 'ready',
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending',
            'installed_version' => null,
        ]);
    }
}
