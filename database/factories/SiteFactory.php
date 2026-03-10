<?php

namespace Database\Factories;

use App\Enums\ProjectType;
use App\Enums\RepositoryProvider;
use App\Enums\SiteStatus;
use App\Models\Server;
use App\Models\SourceControlAccount;
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
        $domain = fake()->unique()->domainName();
        $repo = fake()->userName().'/'.fake()->slug(2);

        return [
            'server_id' => Server::factory(),
            'source_control_account_id' => null,
            'domain' => $domain,
            'site_name' => $domain,
            'aliases' => null,
            'directory' => '/public',
            'repository' => $repo,
            'repository_provider' => RepositoryProvider::Github,
            'branch' => 'main',
            'project_type' => ProjectType::Laravel,
            'php_version' => '8.3',
            'package_manager' => 'composer',
            'build_command' => null,
            'status' => SiteStatus::Deployed,
            'webhook_secret' => Str::random(32),
            'auto_deploy' => false,
            'deployment_finished_at' => now(),
        ];
    }

    public function forServer(Server $server): static
    {
        return $this->state(fn (array $attributes) => [
            'server_id' => $server->id,
            'php_version' => $server->php_version,
        ]);
    }

    public function forSourceControlAccount(SourceControlAccount $account): static
    {
        return $this->state(fn (array $attributes) => [
            'source_control_account_id' => $account->id,
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SiteStatus::Pending,
        ]);
    }

    public function provisioned(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SiteStatus::Provisioned,
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
