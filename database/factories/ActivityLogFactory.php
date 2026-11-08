<?php

namespace Database\Factories;

use App\Enums\ActivityEvent;
use App\Models\ActivityLog;
use App\Models\Site;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActivityLog>
 */
class ActivityLogFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'user_id' => null,
            'subject_type' => null,
            'subject_id' => null,
            'event' => ActivityEvent::SiteEnvironmentUpdated,
            'properties' => [],
            'created_at' => now(),
        ];
    }

    public function forSite(Site $site): static
    {
        return $this->state([
            'team_id' => $site->server->team_id,
            'subject_type' => $site->getMorphClass(),
            'subject_id' => $site->id,
        ]);
    }
}
