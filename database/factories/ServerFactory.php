<?php

namespace Database\Factories;

use App\Enums\Provider;
use App\Enums\ServerStatus;
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
            'name' => fake()->slug(2),
            'size' => 's-1vcpu-1gb',
            'region' => fake()->randomElement(['nyc1', 'sfo1', 'ams2', 'sgp1', 'lon1']),
            'ip_address' => fake()->ipv4(),
            'private_ip_address' => '10.0.0.'.fake()->numberBetween(1, 254),
            'status' => ServerStatus::Active,
            'php_version' => '8.3',
            'database_type' => 'mysql',
            'ssh_port' => 22,
            'provisioned_at' => now(),
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ServerStatus::Pending,
            'ip_address' => null,
            'private_ip_address' => null,
            'provider_server_id' => null,
            'provisioned_at' => null,
        ]);
    }

    public function provisioning(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ServerStatus::Provisioning,
            'provisioned_at' => null,
        ]);
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ServerStatus::Active,
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
