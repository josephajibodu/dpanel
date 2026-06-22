<?php

namespace Database\Factories;

use App\Enums\NginxFileSection;
use App\Models\Site;
use App\Models\SiteDomain;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SiteNginxFile>
 */
class SiteNginxFileFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $snippetSections = array_filter(NginxFileSection::cases(), fn ($s) => $s !== NginxFileSection::Main);
        $section = $this->faker->randomElement($snippetSections);
        $name = $this->faker->word().'.conf';

        return [
            'site_id' => Site::factory(),
            'site_domain_id' => null,
            'section' => $section,
            'path' => "{$section->value}/{$name}",
            'content' => "# Custom nginx snippet\n",
            'sync_status' => 'synced',
            'sync_error' => null,
        ];
    }

    public function forSite(Site $site): static
    {
        return $this->state(['site_id' => $site->id]);
    }

    public function forDomain(SiteDomain $domain): static
    {
        return $this->state([
            'site_id' => $domain->site_id,
            'site_domain_id' => $domain->id,
        ]);
    }

    public function inSection(NginxFileSection $section, string $name): static
    {
        $path = $section === NginxFileSection::Main ? $name : "{$section->value}/{$name}";

        return $this->state([
            'section' => $section,
            'path' => $path,
        ]);
    }

    public function pending(): static
    {
        return $this->state(['sync_status' => 'pending']);
    }

    public function failed(string $error = 'nginx: test failed'): static
    {
        return $this->state(['sync_status' => 'failed', 'sync_error' => $error]);
    }
}
