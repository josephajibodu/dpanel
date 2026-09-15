<?php

namespace Database\Factories;

use App\Enums\StorageProviderType;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\StorageProvider>
 */
class StorageProviderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'user_id' => User::factory(),
            'type' => StorageProviderType::CloudflareR2,
            'name' => fake()->company().' Backups',
            'credentials' => [
                'account_id' => fake()->uuid(),
                'access_key_id' => fake()->regexify('[A-Za-z0-9]{20}'),
                'secret_access_key' => fake()->regexify('[A-Za-z0-9]{40}'),
                'bucket' => fake()->slug(2),
            ],
            'is_valid' => true,
            'validated_at' => now(),
        ];
    }

    public function forTeam(Team $team): static
    {
        return $this->state(fn (array $attributes) => [
            'team_id' => $team->id,
            'user_id' => $team->user_id,
        ]);
    }

    public function s3(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => StorageProviderType::S3,
            'credentials' => [
                'access_key_id' => fake()->regexify('[A-Za-z0-9]{20}'),
                'secret_access_key' => fake()->regexify('[A-Za-z0-9]{40}'),
                'bucket' => fake()->slug(2),
                'region' => 'us-east-1',
            ],
        ]);
    }

    public function invalid(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_valid' => false,
            'validated_at' => null,
        ]);
    }
}
