<?php

namespace Database\Factories;

use App\Enums\SiteCommandRunStatus;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SiteCommandRun>
 */
class SiteCommandRunFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startedAt = fake()->dateTimeBetween('-1 week', 'now');
        $finishedAt = (clone $startedAt)->modify('+'.fake()->numberBetween(1, 30).' seconds');

        return [
            'site_id' => Site::factory(),
            'user_id' => User::factory(),
            'command' => 'php artisan --version',
            'output' => 'Laravel Framework 12.x',
            'status' => SiteCommandRunStatus::Completed,
            'exit_code' => 0,
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SiteCommandRunStatus::Pending,
            'output' => null,
            'exit_code' => null,
            'started_at' => null,
            'finished_at' => null,
        ]);
    }

    public function running(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SiteCommandRunStatus::Running,
            'output' => null,
            'exit_code' => null,
            'started_at' => now(),
            'finished_at' => null,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SiteCommandRunStatus::Completed,
            'exit_code' => 0,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SiteCommandRunStatus::Failed,
            'exit_code' => 1,
        ]);
    }

    public function forSite(Site $site): static
    {
        return $this->state(fn (array $attributes) => [
            'site_id' => $site->id,
        ]);
    }
}
