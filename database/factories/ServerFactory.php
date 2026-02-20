<?php

namespace Database\Factories;

use App\Enums\ConnectionStatus;
use App\Enums\Provider;
use App\Enums\ProvisioningStep;
use App\Enums\ServerStatus;
use App\Enums\ServerType;
use App\Models\ProviderAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Server>
 */
class ServerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $provider = fake()->randomElement(Provider::cases());

        return [
            'user_id' => User::factory(),
            'provider_account_id' => ProviderAccount::factory(),
            'provider' => $provider,
            'provider_server_id' => (string) fake()->randomNumber(8),
            'cloud_provider_url' => null,
            'name' => fake()->slug(2),
            'type' => ServerType::App,
            'size' => 's-1vcpu-1gb',
            'region' => fake()->randomElement(['nyc1', 'sfo1', 'ams2', 'sgp1', 'lon1']),
            'provider_region_id' => null,
            'provider_size_id' => null,
            'ip_address' => fake()->ipv4(),
            'private_ip_address' => '10.0.0.'.fake()->numberBetween(1, 254),
            'status' => ServerStatus::Active,
            'provisioning_step' => ProvisioningStep::Finished,
            'connection_status' => ConnectionStatus::Successful,
            'php_version' => '8.3',
            'database_type' => 'mysql',
            'ubuntu_version' => '24.04',
            'timezone' => 'UTC',
            'notes' => null,
            'archived' => false,
            'ssh_port' => 22,
            'provisioned_at' => now(),
            'last_ssh_connection_at' => now(),
        ];
    }

    public function forUser(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user->id,
        ]);
    }

    public function forProviderAccount(ProviderAccount $account): static
    {
        return $this->state(fn (array $attributes) => [
            'provider_account_id' => $account->id,
            'user_id' => $account->user_id,
            'provider' => $account->provider,
        ]);
    }

    public function app(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => ServerType::App,
        ]);
    }

    public function web(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => ServerType::Web,
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ServerStatus::Pending,
            'provisioning_step' => ProvisioningStep::Pending,
            'connection_status' => ConnectionStatus::Unknown,
            'ip_address' => null,
            'private_ip_address' => null,
            'provider_server_id' => null,
            'provisioned_at' => null,
            'last_ssh_connection_at' => null,
        ]);
    }

    public function provisioning(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ServerStatus::Provisioning,
            'provisioning_step' => ProvisioningStep::InstallingPhp,
            'provisioned_at' => null,
        ]);
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ServerStatus::Active,
            'provisioning_step' => ProvisioningStep::Finished,
            'connection_status' => ConnectionStatus::Successful,
            'provisioned_at' => now(),
        ]);
    }

    public function error(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ServerStatus::Error,
            'provisioned_at' => null,
        ]);
    }
}
