<?php

namespace App\Enums;

/**
 * What a site is built with, as picked on the create-site page.
 *
 * A framework is a finer label than a project type: the server only cares
 * about the project type (how the site is provisioned and served), while the
 * framework decides the starting web directory, build and deploy script.
 * React, Vue and the other JavaScript frameworks all build to plain files,
 * so they are served as static HTML sites.
 *
 * The cases are listed in the order the create-site page shows them.
 */
enum Framework: string
{
    case Laravel = 'laravel';
    case Symfony = 'symfony';
    case WordPress = 'wordpress';
    case Php = 'php';
    case React = 'react';
    case Vue = 'vue';
    case Svelte = 'svelte';
    case Angular = 'angular';
    case Astro = 'astro';
    case Gatsby = 'gatsby';
    case Eleventy = 'eleventy';
    case Docusaurus = 'docusaurus';
    case VitePress = 'vitepress';
    case StaticHtml = 'html';

    /**
     * The framework that stands for a project type on its own. Each project
     * type has a framework with the same value.
     */
    public static function fromProjectType(ProjectType $projectType): self
    {
        return self::from($projectType->value);
    }

    public function label(): string
    {
        return match ($this) {
            self::React => 'React',
            self::Vue => 'Vue',
            self::Svelte => 'Svelte',
            self::Angular => 'Angular',
            self::Astro => 'Astro',
            self::Gatsby => 'Gatsby',
            self::Eleventy => 'Eleventy',
            self::Docusaurus => 'Docusaurus',
            self::VitePress => 'VitePress',
            default => $this->projectType()->label(),
        };
    }

    /**
     * A one-line explanation shown when picking the framework.
     */
    public function description(): string
    {
        return match ($this) {
            self::React, self::Vue, self::Svelte, self::Angular => 'Builds the app on each deploy and serves it as a single-page app.',
            self::Astro, self::Gatsby, self::Eleventy => 'Builds the site on each deploy and serves the generated pages.',
            self::Docusaurus, self::VitePress => 'Builds the docs on each deploy and serves the generated pages.',
            default => $this->projectType()->description(),
        };
    }

    /**
     * How the server provisions and serves a site built with this framework.
     */
    public function projectType(): ProjectType
    {
        return match ($this) {
            self::Laravel => ProjectType::Laravel,
            self::Symfony => ProjectType::Symfony,
            self::WordPress => ProjectType::WordPress,
            self::Php => ProjectType::PhpGeneric,
            default => ProjectType::StaticHtml,
        };
    }

    /**
     * The folder the web server serves, relative to the repository. For the
     * JavaScript frameworks this is where the build puts its files when the
     * project hasn't changed the framework's default.
     */
    public function defaultDirectory(): string
    {
        return match ($this) {
            self::React, self::Vue, self::Svelte, self::Angular, self::Astro => '/dist',
            self::Gatsby => '/public',
            self::Eleventy => '/_site',
            self::Docusaurus => '/build',
            self::VitePress => '/.vitepress/dist',
            default => $this->projectType()->defaultDirectory(),
        };
    }

    /**
     * Whether the code has to be built with Node before it can be served.
     */
    public function buildsWithNode(): bool
    {
        return $this->projectType() === ProjectType::StaticHtml && $this !== self::StaticHtml;
    }

    /**
     * Whether the app draws its own pages in the browser, so every URL that
     * isn't a file must load index.html.
     */
    public function isSinglePageApp(): bool
    {
        return in_array($this, [self::React, self::Vue, self::Svelte, self::Angular], true);
    }

    /**
     * The deploy script a new site starts with.
     *
     * Sites served as static files install their packages and run the build
     * command when a package manager is chosen; without one there is nothing
     * to run. Every other site gets its project type's script.
     */
    public function defaultDeployScript(?string $packageManager = null, ?string $buildCommand = null): string
    {
        $projectType = $this->projectType();

        if ($projectType !== ProjectType::StaticHtml) {
            return $projectType->defaultDeployScript();
        }

        $install = $this->installScript($packageManager);
        $buildCommand = trim((string) $buildCommand);

        if ($install === null || $buildCommand === '') {
            return '';
        }

        return $install."\n\n".$buildCommand;
    }

    /**
     * The commands that install a project's packages, or null when no
     * package manager is used.
     *
     * Servers come with npm only. pnpm, yarn and bun are installed for the
     * deploying user on first use, into its home folder, because that user
     * can't install anything system-wide.
     */
    private function installScript(?string $packageManager): ?string
    {
        return match ($packageManager) {
            'npm' => 'if [ -f package-lock.json ]; then npm ci; else npm install; fi',
            'pnpm', 'yarn', 'bun' => <<<SCRIPT
export PATH="\$HOME/.local/bin:\$PATH"
if ! command -v {$packageManager} > /dev/null; then npm install --global --prefix "\$HOME/.local" {$packageManager}; fi

{$packageManager} install --frozen-lockfile
SCRIPT,
            default => null,
        };
    }
}
