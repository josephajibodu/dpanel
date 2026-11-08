<?php

namespace Database\Factories;

use App\Models\Site;
use App\Models\SiteEnvironmentHistory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SiteEnvironmentHistory>
 */
class SiteEnvironmentHistoryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'user_id' => null,
            'added_keys' => ['APP_NAME'],
            'changed_keys' => [],
            'removed_keys' => [],
            'old_content' => '',
            'new_content' => "APP_NAME=Example\n",
            'created_at' => now(),
        ];
    }

    public function forSite(Site $site): static
    {
        return $this->state(['site_id' => $site->id]);
    }
}
