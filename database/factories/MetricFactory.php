<?php

namespace Database\Factories;

use App\Models\Server;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Metric>
 */
class MetricFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $memoryTotal = fake()->numberBetween(1, 32) * 1024 * 1024 * 1024;
        $memoryUsed = (int) ($memoryTotal * fake()->randomFloat(2, 0.2, 0.8));
        $diskTotal = fake()->numberBetween(20, 500) * 1024 * 1024 * 1024;
        $diskUsed = (int) ($diskTotal * fake()->randomFloat(2, 0.3, 0.7));

        return [
            'server_id' => Server::factory(),
            'load' => fake()->randomFloat(2, 0.1, 4.0),
            'memory_total' => $memoryTotal,
            'memory_used' => $memoryUsed,
            'memory_free' => $memoryTotal - $memoryUsed,
            'disk_total' => $diskTotal,
            'disk_used' => $diskUsed,
            'disk_free' => $diskTotal - $diskUsed,
        ];
    }
}
