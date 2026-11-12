<?php

namespace App\Data;

use App\Enums\Framework;

/**
 * What FlitOps worked out about a repository by looking at its files, used
 * to fill in the create-site form.
 */
readonly class DetectedFramework
{
    /**
     * @param  string  $directory  The folder to serve, e.g. `/dist`.
     * @param  string|null  $packageManager  `none` when nothing needs building; null when the framework doesn't build with Node.
     * @param  string|null  $buildCommand  Null when there is nothing to build.
     */
    public function __construct(
        public Framework $framework,
        public string $directory,
        public ?string $packageManager = null,
        public ?string $buildCommand = null,
    ) {}

    /**
     * @return array{framework: string, directory: string, package_manager: string|null, build_command: string|null, single_page_app: bool}
     */
    public function toArray(): array
    {
        return [
            'framework' => $this->framework->value,
            'directory' => $this->directory,
            'package_manager' => $this->packageManager,
            'build_command' => $this->buildCommand,
            'single_page_app' => $this->framework->isSinglePageApp(),
        ];
    }
}
