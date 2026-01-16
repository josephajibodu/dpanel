<?php

namespace Database\Factories;

use App\Enums\Provider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ProviderSize>
 */
class ProviderSizeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $memory = fake()->randomElement([512, 1024, 2048, 4096, 8192]);
        $cpus = fake()->randomElement([1, 2, 4, 8]);
        $disk = fake()->randomElement([10, 25, 50, 80, 160]);

        return [
            'provider' => fake()->randomElement(Provider::cases()),
            'code' => fake()->unique()->slug(3),
            'name' => $this->formatMemory($memory).' RAM · '.$cpus.' vCPU'.($cpus > 1 ? 's' : '').' · '.$disk.' GB SSD',
            'label' => null,
            'memory' => $this->formatMemory($memory),
            'disk' => $disk.' GB',
            'cpus' => $cpus,
            'price_monthly' => fake()->randomFloat(2, 4, 100),
        ];
    }

    private function formatMemory(int $mb): string
    {
        return $mb >= 1024 ? ($mb / 1024).' GB' : $mb.' MB';
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
