<?php

namespace Database\Factories;

use App\Models\ServerDatabase;
use App\Models\StorageProvider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Backup>
 */
class BackupFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'server_database_id' => ServerDatabase::factory(),
            'storage_provider_id' => StorageProvider::factory(),
            'status' => 'pending',
            'triggered_by' => 'manual',
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
            'storage_path' => 'backups/'.fake()->uuid().'.sql.gz',
            'size_bytes' => fake()->numberBetween(1024, 1024 * 1024 * 50),
            'verified_at' => now(),
            'started_at' => now()->subMinutes(2),
            'finished_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'failed',
            'error_message' => 'Something went wrong.',
            'started_at' => now()->subMinutes(2),
            'finished_at' => now(),
        ]);
    }

    public function scheduled(): static
    {
        return $this->state(fn (array $attributes) => [
            'triggered_by' => 'scheduled',
        ]);
    }
}
