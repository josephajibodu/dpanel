<?php

namespace Database\Factories;

use App\Enums\SiteStatus;
use App\Models\Server;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Site>
 */
class SiteFactory extends Factory
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
            'domain' => fake()->unique()->domainName(),
            'aliases' => null,
            'directory' => '/public',
            'repository' => 'git@github.com:'.fake()->userName().'/'.fake()->slug(2).'.git',
            'repository_provider' => 'github',
            'branch' => 'main',
            'project_type' => 'laravel',
            'php_version' => '8.3',
            'status' => SiteStatus::Deployed,
            'webhook_secret' => Str::random(32),
            'auto_deploy' => false,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SiteStatus::Pending,
        ]);
    }

    public function deploying(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SiteStatus::Deploying,
            'deployment_started_at' => now(),
        ]);
    }

    public function deployed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SiteStatus::Deployed,
            'deployment_finished_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SiteStatus::Failed,
        ]);
    }

    public function withAutoDeploy(): static
    {
        return $this->state(fn (array $attributes) => [
            'auto_deploy' => true,
        ]);
    }
}
