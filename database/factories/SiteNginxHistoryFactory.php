<?php

namespace Database\Factories;

use App\Enums\NginxHistoryEvent;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SiteNginxHistory>
 */
class SiteNginxHistoryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'site_nginx_file_id' => null,
            'user_id' => null,
            'event' => NginxHistoryEvent::Created,
            'path' => 'server/custom.conf',
            'old_path' => null,
            'old_content' => null,
            'new_content' => "# snippet\n",
            'created_at' => now(),
        ];
    }

    public function forSite(Site $site): static
    {
        return $this->state(['site_id' => $site->id]);
    }
}
