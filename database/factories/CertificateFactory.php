<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Certificate>
 */
class CertificateFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $domain = '*.'.fake()->unique()->domainName();
        $basePath = storage_path("app/certificates/{$domain}");

        return [
            'domain' => $domain,
            'certificate_path' => "{$basePath}/server.crt",
            'private_key_path' => "{$basePath}/server.key",
            'chain_path' => "{$basePath}/chain.pem",
            'expires_at' => now()->addDays(80),
            'last_renewed_at' => now(),
            'last_distribution_at' => null,
        ];
    }

    public function expiringSoon(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => now()->addDays(10),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => now()->subDay(),
        ]);
    }

    public function freeDomainWildcard(): static
    {
        return $this->state(fn (array $attributes) => [
            'domain' => '*.'.config('server.free_domain'),
        ]);
    }
}
