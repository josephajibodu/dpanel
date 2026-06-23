<?php

namespace Database\Factories;

use App\Enums\SiteDomainType;
use App\Enums\WwwRedirect;
use App\Models\SiteDomain;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SiteDomain>
 */
class SiteDomainFactory extends Factory
{
    protected $model = SiteDomain::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'hostname' => fake()->unique()->domainName(),
            'type' => SiteDomainType::Custom,
            'is_primary' => true,
            'wildcard_enabled' => false,
            'www_redirect' => WwwRedirect::None,
            'is_enabled' => true,
            'cloudflare_dns_record_id' => null,
            'verified_at' => null,
            'ssl_enabled_at' => null,
        ];
    }

    public function system(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => SiteDomainType::System,
        ]);
    }

    public function primary(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_primary' => true,
        ]);
    }

    public function notPrimary(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_primary' => false,
        ]);
    }
}
