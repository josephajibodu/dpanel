<?php

namespace Database\Factories;

use App\Enums\Provider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ProviderRegion>
 */
class ProviderRegionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider' => fake()->randomElement(Provider::cases()),
            'code' => fake()->unique()->slug(2),
            'name' => fake()->city().' '.fake()->randomNumber(1),
            'alternate_code' => null,
        ];
    }

    public function digitalOcean(): static
    {
        return $this->state(fn (array $attributes) => [
            'provider' => Provider::DigitalOcean,
        ]);
    }

    public function hetzner(): static
    {
        return $this->state(fn (array $attributes) => [
            'provider' => Provider::Hetzner,
        ]);
    }

    public function vultr(): static
    {
        return $this->state(fn (array $attributes) => [
            'provider' => Provider::Vultr,
        ]);
    }
}
