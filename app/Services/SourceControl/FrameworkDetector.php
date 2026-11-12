<?php

namespace App\Services\SourceControl;

use App\Data\DetectedFramework;
use App\Enums\Framework;
use App\Models\Site;
use App\Models\SourceControlAccount;
use App\Services\SourceControlService;

/**
 * Works out what an app in a repository is built with from the files in
 * its folder: which framework, which folder to serve, and how to build it.
 * The app is at the top of the repository unless a folder is given.
 *
 * It reads the list of files in the app's folder, then only the one or two
 * files that settle the answer (composer.json, package.json, angular.json).
 */
class FrameworkDetector
{
    /**
     * Files that only a WordPress site has at its top level.
     */
    private const WORDPRESS_FILES = ['wp-config.php', 'wp-config-sample.php', 'wp-load.php', 'wp-content'];

    /**
     * The lockfile each package manager leaves in a repository. A project
     * with none of these is installed with npm.
     */
    private const LOCKFILES = [
        'pnpm-lock.yaml' => 'pnpm',
        'yarn.lock' => 'yarn',
        'bun.lockb' => 'bun',
        'bun.lock' => 'bun',
    ];

    /**
     * Folder listings already fetched for this request, by repository,
     * branch and folder.
     *
     * @var array<string, array<int, array{name: string, directory: bool}>>
     */
    private array $listings = [];

    public function __construct(
        private SourceControlService $sourceControl,
    ) {}

    /**
     * The folders at the top of a repository that could hold an app, for
     * repositories that keep more than one. Hidden folders such as .github
     * are left out, and so are names a site can't have as its app directory.
     *
     * @return array<int, string>
     */
    public function folders(SourceControlAccount $account, string $repository, string $branch): array
    {
        return collect($this->entries($account, $repository, $branch))
            ->filter(fn (array $entry): bool => $entry['directory']
                && ! str_starts_with($entry['name'], '.')
                && preg_match(Site::APP_DIRECTORY_PATTERN, $entry['name']) === 1)
            ->pluck('name')
            ->values()
            ->all();
    }

    /**
     * What the app in one folder of a repository is built with; the top of
     * the repository when no folder is given. Null when the folder can't be
     * read or nothing in it is recognised.
     */
    public function detect(SourceControlAccount $account, string $repository, string $branch, string $folder = ''): ?DetectedFramework
    {
        $files = array_column($this->entries($account, $repository, $branch, $folder), 'name');

        $read = fn (string $file): ?string => in_array($file, $files, true)
            ? $this->sourceControl->readFile($account, $repository, $branch, ltrim("{$folder}/{$file}", '/'))
            : null;

        if (array_intersect(self::WORDPRESS_FILES, $files) !== []) {
            return $this->withoutBuild(Framework::WordPress);
        }

        if (in_array('composer.json', $files, true)) {
            return $this->withoutBuild($this->phpFramework($this->decodeJson($read('composer.json'))));
        }

        if (in_array('index.php', $files, true)) {
            return $this->withoutBuild(Framework::Php);
        }

        // A workspace keeps one lockfile at the top of the repository for
        // every app in it, so that is looked at too.
        $lockfileCandidates = $folder === '' ? $files : [...$files, ...array_column($this->entries($account, $repository, $branch), 'name')];

        $detected = $this->detectFromPackageJson($this->decodeJson($read('package.json')), $files, $lockfileCandidates, $read);

        if ($detected !== null) {
            return $detected;
        }

        if (in_array('index.html', $files, true)) {
            return new DetectedFramework(Framework::StaticHtml, Framework::StaticHtml->defaultDirectory(), packageManager: 'none');
        }

        return null;
    }

    /**
     * @return array<int, array{name: string, directory: bool}>
     */
    private function entries(SourceControlAccount $account, string $repository, string $branch, string $folder = ''): array
    {
        return $this->listings["{$repository}@{$branch}:{$folder}"]
            ??= $this->sourceControl->listEntries($account, $repository, $branch, $folder);
    }

    private function withoutBuild(Framework $framework): DetectedFramework
    {
        return new DetectedFramework($framework, $framework->defaultDirectory());
    }

    /**
     * @param  array<string, mixed>  $composer
     */
    private function phpFramework(array $composer): Framework
    {
        $packages = is_array($composer['require'] ?? null) ? $composer['require'] : [];

        return match (true) {
            array_key_exists('laravel/framework', $packages) => Framework::Laravel,
            array_key_exists('symfony/framework-bundle', $packages) => Framework::Symfony,
            default => Framework::Php,
        };
    }

    /**
     * @param  array<string, mixed>  $package
     * @param  array<int, string>  $files  Names in the app's folder.
     * @param  array<int, string>  $lockfileCandidates  Names to look for a lockfile among.
     * @param  callable(string): ?string  $read  Reads a file in the app's folder.
     */
    private function detectFromPackageJson(array $package, array $files, array $lockfileCandidates, callable $read): ?DetectedFramework
    {
        $dependencies = [
            ...(is_array($package['dependencies'] ?? null) ? $package['dependencies'] : []),
            ...(is_array($package['devDependencies'] ?? null) ? $package['devDependencies'] : []),
        ];
        $uses = fn (string $name): bool => array_key_exists($name, $dependencies);

        // Next.js and Nuxt apps normally need a running Node server, which
        // a static site can't give them.
        if ($uses('next') || $uses('nuxt')) {
            return null;
        }

        // Frameworks built on React or Vue come first, because those
        // packages are in their projects too.
        $framework = match (true) {
            $uses('@docusaurus/core') => Framework::Docusaurus,
            $uses('vitepress') => Framework::VitePress,
            $uses('astro') => Framework::Astro,
            $uses('gatsby') => Framework::Gatsby,
            $uses('@11ty/eleventy') => Framework::Eleventy,
            $uses('@angular/core') => Framework::Angular,
            $uses('svelte') => Framework::Svelte,
            $uses('vue') => Framework::Vue,
            $uses('react') => Framework::React,
            default => null,
        };

        if ($framework === null) {
            return null;
        }

        $scripts = array_filter(is_array($package['scripts'] ?? null) ? $package['scripts'] : [], is_string(...));
        $vitePressBuild = $framework === Framework::VitePress ? $this->vitePressBuild($scripts) : null;

        $directory = match (true) {
            // Create React App and SvelteKit build to /build; Vite builds to /dist.
            $framework === Framework::React && $uses('react-scripts'),
            $framework === Framework::Svelte && $uses('@sveltejs/kit') => '/build',
            $framework === Framework::Angular => $this->angularDirectory($this->decodeJson($read('angular.json'))),
            $vitePressBuild !== null => '/'.ltrim($vitePressBuild['root'].'/.vitepress/dist', '/'),
            default => $framework->defaultDirectory(),
        };

        $packageManager = collect(self::LOCKFILES)->first(fn (string $manager, string $lockfile): bool => in_array($lockfile, $lockfileCandidates, true)) ?? 'npm';

        $buildScript = match (true) {
            $vitePressBuild !== null => $vitePressBuild['script'],
            default => array_key_exists('build', $scripts) ? 'build' : null,
        };

        $buildCommand = match (true) {
            $buildScript !== null => $this->runScript($packageManager, $buildScript),
            // Projects without the script run the framework's own command.
            $framework === Framework::Eleventy => 'npx @11ty/eleventy',
            default => $this->runScript($packageManager, 'build'),
        };

        return new DetectedFramework(
            $framework,
            preg_match(Site::DIRECTORY_PATTERN, $directory) === 1 ? $directory : $framework->defaultDirectory(),
            $packageManager,
            $buildCommand,
        );
    }

    /**
     * How a package manager runs one of the scripts in package.json.
     */
    private function runScript(string $packageManager, string $script): string
    {
        return $packageManager === 'yarn' ? "yarn {$script}" : "{$packageManager} run {$script}";
    }

    /**
     * The script that builds a VitePress site, and the folder the site
     * lives in. `vitepress build docs` builds to `docs/.vitepress/dist`.
     *
     * @param  array<string, string>  $scripts
     * @return array{script: string, root: string}|null
     */
    private function vitePressBuild(array $scripts): ?array
    {
        foreach ($scripts as $name => $command) {
            if (preg_match('/\bvitepress build(?:\s+([\w.\/-]+))?/', $command, $matches) === 1) {
                return ['script' => (string) $name, 'root' => $this->relativePath($matches[1] ?? '')];
            }
        }

        return null;
    }

    /**
     * Where the Angular CLI puts the built app, read from angular.json.
     *
     * The folder is `dist/<project name>` unless the project sets
     * `outputPath`. The newer "application" builder then adds a `browser`
     * folder inside it, which is the one to serve.
     *
     * @param  array<string, mixed>  $workspace
     */
    private function angularDirectory(array $workspace): string
    {
        $projects = is_array($workspace['projects'] ?? null) ? $workspace['projects'] : [];
        $name = array_key_first($projects);

        if ($name === null) {
            return Framework::Angular->defaultDirectory();
        }

        $build = $projects[$name]['architect']['build'] ?? [];
        $outputPath = $build['options']['outputPath'] ?? null;

        if (is_array($outputPath)) {
            $base = $outputPath['base'] ?? "dist/{$name}";
            $browser = $outputPath['browser'] ?? 'browser';
        } else {
            $base = $outputPath ?? "dist/{$name}";
            $browser = str_ends_with((string) ($build['builder'] ?? ''), ':application') ? 'browser' : '';
        }

        return '/'.trim($this->relativePath((string) $base).'/'.$browser, '/');
    }

    /**
     * A path as written in a config file (`./docs/`, `docs`, `.`) without
     * its leading `./` or trailing slash.
     */
    private function relativePath(string $path): string
    {
        return trim((string) preg_replace('#^\.(/|$)#', '', $path), '/');
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(?string $contents): array
    {
        $decoded = json_decode((string) $contents, true);

        return is_array($decoded) ? $decoded : [];
    }
}
