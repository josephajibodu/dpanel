<?php

namespace Database\Factories;

use App\Models\SourceControlAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SourceControlRepository>
 */
class SourceControlRepositoryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $owner = fake()->userName();
        $name = fake()->slug(2);

        return [
            'source_control_account_id' => SourceControlAccount::factory(),
            'provider_repo_id' => (string) fake()->unique()->randomNumber(8),
            'name' => $name,
            'full_name' => "{$owner}/{$name}",
            'ssh_url' => "git@github.com:{$owner}/{$name}.git",
            'html_url' => "https://github.com/{$owner}/{$name}",
            'default_branch' => 'main',
            'private' => false,
        ];
    }

    public function forAccount(SourceControlAccount $account): static
    {
        return $this->state(fn (array $attributes) => [
            'source_control_account_id' => $account->id,
        ]);
    }
}
