<?php

namespace Database\Factories;

use App\Models\Server;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\DatabaseUser>
 */
class DatabaseUserFactory extends Factory
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
            'username' => fake()->userName().'_'.fake()->randomNumber(3),
            'password' => Str::random(32),
            'databases' => [],
            'permission' => 'readwrite',
            'host' => 'localhost',
            'status' => 'ready',
        ];
    }
}
