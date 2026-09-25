<?php

namespace Database\Factories;

use App\Models\ServerDatabase;
use App\Models\Site;
use App\Models\StorageProvider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\BackupSchedule>
 */
class BackupScheduleFactory extends Factory
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
            'frequency' => 'daily',
            'retention_count' => 7,
            'enabled' => false,
            'next_run_at' => null,
        ];
    }

    public function enabled(): static
    {
        return $this->state(fn (array $attributes) => [
            'enabled' => true,
            'next_run_at' => now()->addDay(),
        ]);
    }

    public function due(): static
    {
        return $this->state(fn (array $attributes) => [
            'enabled' => true,
            'next_run_at' => now()->subMinute(),
        ]);
    }

    /**
     * Target a site's SQLite database instead of a server database.
     */
    public function forSite(Site $site): static
    {
        return $this->state(fn (array $attributes) => [
            'server_database_id' => null,
            'site_id' => $site->id,
        ]);
    }
}
