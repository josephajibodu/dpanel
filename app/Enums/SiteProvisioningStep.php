<?php

namespace App\Enums;

enum SiteProvisioningStep: int
{
    case Initializing = 0;
    case ConfiguringNginx = 1;
    case CloningRepository = 2;
    case CreatingEnvironmentFile = 3;
    case InstallingDependencies = 4;
    case BuildingFrontendAssets = 5;
    case RunningDatabaseMigrations = 6;
    case MakingFinalTouches = 7;

    public function label(): string
    {
        return match ($this) {
            self::Initializing => 'Initializing',
            self::ConfiguringNginx => 'Configuring Nginx',
            self::CloningRepository => 'Cloning Git repository',
            self::CreatingEnvironmentFile => 'Creating environment file',
            self::InstallingDependencies => 'Installing dependencies',
            self::BuildingFrontendAssets => 'Building frontend assets',
            self::RunningDatabaseMigrations => 'Running database migrations',
            self::MakingFinalTouches => 'Making final touches',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Initializing => 'We are preparing to install your application. This may take a few moments.',
            self::ConfiguringNginx => 'We are configuring Nginx to serve your application.',
            self::CloningRepository => 'We are cloning your Git repository.',
            self::CreatingEnvironmentFile => 'We are creating your environment configuration file.',
            self::InstallingDependencies => 'We are installing your application dependencies.',
            self::BuildingFrontendAssets => 'We are building your frontend assets.',
            self::RunningDatabaseMigrations => 'We are running your database migrations.',
            self::MakingFinalTouches => 'We are making final configuration changes.',
        };
    }

    /**
     * Get enum cases for steps to execute for a project type.
     *
     * @return array<self>
     */
    public static function enumCasesForProjectType(\App\Enums\ProjectType $projectType): array
    {
        return match ($projectType) {
            ProjectType::Laravel => [
                self::Initializing,
                self::ConfiguringNginx,
                self::CloningRepository,
                self::CreatingEnvironmentFile,
                self::InstallingDependencies,
                self::BuildingFrontendAssets,
                self::RunningDatabaseMigrations,
                self::MakingFinalTouches,
            ],
            ProjectType::Symfony => [
                self::Initializing,
                self::ConfiguringNginx,
                self::CloningRepository,
                self::CreatingEnvironmentFile,
                self::InstallingDependencies,
                self::MakingFinalTouches,
            ],
            ProjectType::PhpGeneric => [
                self::Initializing,
                self::ConfiguringNginx,
                self::CloningRepository,
                self::CreatingEnvironmentFile,
                self::InstallingDependencies,
                self::MakingFinalTouches,
            ],
            ProjectType::StaticHtml, ProjectType::WordPress => [
                self::Initializing,
                self::ConfiguringNginx,
                self::CloningRepository,
                self::CreatingEnvironmentFile,
                self::MakingFinalTouches,
            ],
        };
    }

    /**
     * Get displayable steps for a project type.
     * Steps are tailored per type; unimplemented steps (e.g. migrations) are omitted for now.
     *
     * @return array<array{value: int, label: string, description: string}>
     */
    public static function stepsForProjectType(\App\Enums\ProjectType $projectType): array
    {
        $steps = self::enumCasesForProjectType($projectType);

        return array_map(fn (self $step) => [
            'value' => $step->value,
            'label' => $step->label(),
            'description' => $step->description(),
        ], $steps);
    }
}
