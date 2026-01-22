<?php

namespace App\Services\Nginx;

use App\Enums\ProjectType;
use App\Models\Site;

class NginxConfigGeneratorFactory
{
    /**
     * Create the appropriate generator for the given site.
     */
    public static function make(Site $site): BaseNginxConfigGenerator
    {
        $projectType = $site->project_type ?? ProjectType::Laravel;

        return match ($projectType) {
            ProjectType::Laravel, ProjectType::Symfony => new FrameworkNginxConfigGenerator,
            ProjectType::PhpGeneric => new PhpNginxConfigGenerator,
            ProjectType::StaticHtml => new StaticNginxConfigGenerator,
            ProjectType::WordPress => new WordPressNginxConfigGenerator,
        };
    }
}
