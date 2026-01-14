<?php

namespace Database\Factories;

use App\Enums\DeploymentStatus;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Deployment>
 */
class DeploymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startedAt = fake()->dateTimeBetween('-1 week', 'now');
        $finishedAt = (clone $startedAt)->modify('+'.fake()->numberBetween(10, 120).' seconds');

        return [
            'site_id' => Site::factory(),
            'user_id' => User::factory(),
            'commit_hash' => fake()->sha1(),
            'commit_message' => fake()->sentence(),
            'commit_author' => fake()->name(),
            'status' => DeploymentStatus::Finished,
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
            'duration_seconds' => $finishedAt->getTimestamp() - $startedAt->getTimestamp(),
            'triggered_by' => fake()->randomElement(['manual', 'webhook', 'api']),
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DeploymentStatus::Pending,
            'started_at' => null,
            'finished_at' => null,
            'duration_seconds' => null,
        ]);
    }

    public function running(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DeploymentStatus::Running,
            'started_at' => now(),
            'finished_at' => null,
            'duration_seconds' => null,
        ]);
    }

    public function finished(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DeploymentStatus::Finished,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DeploymentStatus::Failed,
        ]);
    }

    public function manual(): static
    {
        return $this->state(fn (array $attributes) => [
            'triggered_by' => 'manual',
        ]);
    }

    public function webhook(): static
    {
        return $this->state(fn (array $attributes) => [
            'triggered_by' => 'webhook',
            'user_id' => null,
        ]);
    }
}
