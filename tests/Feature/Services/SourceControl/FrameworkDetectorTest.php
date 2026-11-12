<?php

use App\Models\SourceControlAccount;
use App\Services\SourceControl\FrameworkDetector;
use Illuminate\Support\Facades\Http;

/**
 * Fake one folder of a GitHub repository (acme/app, branch main): its
 * listing and the files in it. A name ending in a slash is a folder; any
 * other value is the file's contents, with an array stored as JSON.
 *
 * @param  array<string, array<mixed>|string>  $entries
 * @return array<string, mixed>
 */
function fakeGithubFolder(array $entries, string $folder = ''): array
{
    $path = 'api.github.com/repos/acme/app/contents'.($folder === '' ? '' : "/{$folder}");

    return [
        "{$path}?ref=main" => Http::response(collect($entries)->keys()->map(fn (string $name): array => [
            'name' => rtrim($name, '/'),
            'type' => str_ends_with($name, '/') ? 'dir' : 'file',
        ])->all()),
        ...collect($entries)
            ->reject(fn (array|string $contents, string $name): bool => str_ends_with($name, '/'))
            ->mapWithKeys(fn (array|string $contents, string $name): array => [
                "{$path}/{$name}?ref=main" => Http::response(is_array($contents) ? json_encode($contents) : $contents),
            ])->all(),
    ];
}

/**
 * Say what was detected at the top of a repository holding the given
 * entries. Any request for something the fake repository doesn't hold
 * fails the test.
 *
 * @param  array<string, array<mixed>|string>  $entries
 * @return array<string, mixed>|null
 */
function detectFrameworkOf(array $entries): ?array
{
    Http::preventStrayRequests();
    Http::fake(fakeGithubFolder($entries));

    return app(FrameworkDetector::class)
        ->detect(SourceControlAccount::factory()->github()->create(), 'acme/app', 'main')
        ?->toArray();
}

it('detects a Vite React app as a single-page app built to /dist with npm', function () {
    $detected = detectFrameworkOf([
        'index.html' => '',
        'package-lock.json' => '',
        'package.json' => [
            'scripts' => ['build' => 'vite build'],
            'dependencies' => ['react' => '^19.0.0', 'react-dom' => '^19.0.0'],
            'devDependencies' => ['vite' => '^7.0.0'],
        ],
    ]);

    expect($detected)->toBe([
        'framework' => 'react',
        'directory' => '/dist',
        'package_manager' => 'npm',
        'build_command' => 'npm run build',
        'single_page_app' => true,
        'warning' => null,
    ]);
});

it('serves the build folder of a Create React App project', function () {
    $detected = detectFrameworkOf([
        'package.json' => ['scripts' => ['build' => 'react-scripts build'], 'dependencies' => ['react' => '^18.0.0', 'react-scripts' => '5.0.1']],
    ]);

    expect($detected['framework'])->toBe('react')
        ->and($detected['directory'])->toBe('/build');
});

it('builds with the package manager whose lockfile is in the repository', function (string $lockfile, string $packageManager, string $buildCommand) {
    $detected = detectFrameworkOf([
        $lockfile => '',
        'package.json' => ['scripts' => ['build' => 'vite build'], 'dependencies' => ['vue' => '^3.5.0']],
    ]);

    expect($detected['package_manager'])->toBe($packageManager)
        ->and($detected['build_command'])->toBe($buildCommand);
})->with([
    ['pnpm-lock.yaml', 'pnpm', 'pnpm run build'],
    ['yarn.lock', 'yarn', 'yarn build'],
    ['bun.lock', 'bun', 'bun run build'],
]);

it('detects a site generator ahead of the React or Vue it is built on', function (array $dependencies, string $framework, string $directory) {
    $detected = detectFrameworkOf([
        'package.json' => ['scripts' => ['build' => 'build'], 'dependencies' => $dependencies],
    ]);

    expect($detected['framework'])->toBe($framework)
        ->and($detected['directory'])->toBe($directory)
        ->and($detected['single_page_app'])->toBeFalse();
})->with([
    'Docusaurus' => [['@docusaurus/core' => '^3.0.0', 'react' => '^18.0.0'], 'docusaurus', '/build'],
    'Astro' => [['astro' => '^5.0.0', 'react' => '^19.0.0'], 'astro', '/dist'],
    'Gatsby' => [['gatsby' => '^5.0.0', 'react' => '^18.0.0'], 'gatsby', '/public'],
]);

it('serves a VitePress site from inside the folder its build script names', function () {
    $detected = detectFrameworkOf([
        'package.json' => [
            'scripts' => ['docs:dev' => 'vitepress dev docs', 'docs:build' => 'vitepress build docs'],
            'devDependencies' => ['vitepress' => '^1.6.0', 'vue' => '^3.5.0'],
        ],
    ]);

    expect($detected['framework'])->toBe('vitepress')
        ->and($detected['directory'])->toBe('/docs/.vitepress/dist')
        ->and($detected['build_command'])->toBe('npm run docs:build');
});

it('runs Eleventy directly when the project has no build script', function () {
    $detected = detectFrameworkOf([
        'package.json' => ['devDependencies' => ['@11ty/eleventy' => '^3.0.0']],
    ]);

    expect($detected['framework'])->toBe('eleventy')
        ->and($detected['directory'])->toBe('/_site')
        ->and($detected['build_command'])->toBe('npx @11ty/eleventy');
});

it('detects a Next.js app that exports plain files, served from /out', function () {
    $detected = detectFrameworkOf([
        'next.config.mjs' => "export default { output: 'export', trailingSlash: true };",
        'package.json' => ['scripts' => ['build' => 'next build'], 'dependencies' => ['next' => '^16.0.0', 'react' => '^19.0.0']],
    ]);

    expect($detected)->toBe([
        'framework' => 'nextjs',
        'directory' => '/out',
        'package_manager' => 'npm',
        'build_command' => 'npm run build',
        'single_page_app' => false,
        'warning' => null,
    ]);
});

it('warns about a Next.js app that will not work as a static site', function (array $files, string $warning) {
    $detected = detectFrameworkOf([
        ...$files,
        'package.json' => ['scripts' => ['build' => 'next build'], 'dependencies' => ['next' => '^16.0.0', 'react' => '^19.0.0']],
    ]);

    expect($detected['framework'])->toBe('nextjs')
        ->and($detected['warning'])->toBe($warning);
})->with([
    'no static export in its config' => [
        ['next.config.ts' => 'export default { reactStrictMode: true };'],
        "This Next.js app isn't set up to export plain files (output: 'export' in its Next.js config). It needs a running Node server, which FlitOps can't provide yet, so the site won't work as it is.",
    ],
    'no config file at all' => [
        [],
        "This Next.js app isn't set up to export plain files (output: 'export' in its Next.js config). It needs a running Node server, which FlitOps can't provide yet, so the site won't work as it is.",
    ],
    'a static export without trailing slashes' => [
        ['next.config.js' => 'module.exports = { output: "export" };'],
        "Set trailingSlash: true in the Next.js config. Without it, pages other than the home page won't load when opened directly.",
    ],
]);

it('builds a Nuxt app with its generate command, which makes plain files', function (array $package, string $directory, string $buildCommand) {
    $detected = detectFrameworkOf(['package.json' => $package]);

    expect($detected['framework'])->toBe('nuxt')
        ->and($detected['directory'])->toBe($directory)
        ->and($detected['build_command'])->toBe($buildCommand)
        ->and($detected['warning'])->toBeNull();
})->with([
    'with a generate script' => [['scripts' => ['build' => 'nuxt build', 'generate' => 'nuxt generate'], 'dependencies' => ['nuxt' => '^4.0.0', 'vue' => '^3.5.0']], '/.output/public', 'npm run generate'],
    'without one' => [['scripts' => ['build' => 'nuxt build'], 'devDependencies' => ['nuxt' => '^3.15.0']], '/.output/public', 'npx nuxt generate'],
    'Nuxt 2, which generates into /dist' => [['scripts' => ['generate' => 'nuxt generate'], 'dependencies' => ['nuxt' => '^2.18.1']], '/dist', 'npm run generate'],
]);

it('detects the app in a chosen folder, using the lockfile at the top of the repository', function () {
    Http::preventStrayRequests();
    Http::fake([
        ...fakeGithubFolder(['backend/' => '', 'frontend/' => '', 'pnpm-lock.yaml' => '', 'README.md' => '']),
        ...fakeGithubFolder([
            'package.json' => ['scripts' => ['build' => 'vite build'], 'dependencies' => ['react' => '^19.0.0']],
            'index.html' => '',
        ], 'frontend'),
    ]);

    $detected = app(FrameworkDetector::class)
        ->detect(SourceControlAccount::factory()->github()->create(), 'acme/app', 'main', 'frontend')
        ->toArray();

    expect($detected['framework'])->toBe('react')
        ->and($detected['directory'])->toBe('/dist')
        ->and($detected['package_manager'])->toBe('pnpm')
        ->and($detected['build_command'])->toBe('pnpm run build');
});

it('detects a PHP app in a chosen folder', function () {
    Http::preventStrayRequests();
    Http::fake(fakeGithubFolder(['artisan' => '', 'composer.json' => ['require' => ['laravel/framework' => '^13.0']]], 'backend'));

    $detected = app(FrameworkDetector::class)
        ->detect(SourceControlAccount::factory()->github()->create(), 'acme/app', 'main', 'backend')
        ->toArray();

    expect($detected['framework'])->toBe('laravel')
        ->and($detected['directory'])->toBe('/public');
});

it('lists the folders an app could be in, leaving out files, hidden folders and names a site cannot use', function () {
    Http::preventStrayRequests();
    Http::fake(fakeGithubFolder(['.github/' => '', 'backend/' => '', 'frontend/' => '', 'old site/' => '', 'README.md' => '']));

    $folders = app(FrameworkDetector::class)->folders(SourceControlAccount::factory()->github()->create(), 'acme/app', 'main');

    expect($folders)->toBe(['backend', 'frontend']);
});

it('reads where an Angular app is built from angular.json', function (array $build, string $directory) {
    $detected = detectFrameworkOf([
        'package.json' => ['scripts' => ['build' => 'ng build'], 'dependencies' => ['@angular/core' => '^20.0.0']],
        'angular.json' => ['projects' => ['shop' => ['architect' => ['build' => $build]]]],
    ]);

    expect($detected['framework'])->toBe('angular')
        ->and($detected['directory'])->toBe($directory);
})->with([
    'application builder adds a browser folder' => [['builder' => '@angular/build:application'], '/dist/shop/browser'],
    'older browser builder does not' => [['builder' => '@angular-devkit/build-angular:browser', 'options' => ['outputPath' => 'dist/store']], '/dist/store'],
    'outputPath given in parts' => [['builder' => '@angular/build:application', 'options' => ['outputPath' => ['base' => 'out', 'browser' => '']]], '/out'],
    'a path outside the repository falls back to /dist' => [['builder' => '@angular/build:application', 'options' => ['outputPath' => '../public']], '/dist'],
]);

it('detects a PHP project from its composer.json, even when it also has a package.json', function (array $require, string $framework, string $directory) {
    $detected = detectFrameworkOf([
        'composer.json' => ['require' => $require],
        'package.json' => ['scripts' => ['build' => 'vite build'], 'dependencies' => ['vue' => '^3.5.0']],
    ]);

    expect($detected)->toBe([
        'framework' => $framework,
        'directory' => $directory,
        'package_manager' => null,
        'build_command' => null,
        'single_page_app' => false,
        'warning' => null,
    ]);
})->with([
    'Laravel' => [['php' => '^8.4', 'laravel/framework' => '^13.0'], 'laravel', '/public'],
    'Symfony' => [['symfony/framework-bundle' => '^7.0'], 'symfony', '/public'],
    'plain PHP' => [['monolog/monolog' => '^3.0'], 'php', '/'],
]);

it('detects a project from the files at its top level alone', function (array $files, string $framework) {
    expect(detectFrameworkOf($files)['framework'])->toBe($framework);
})->with([
    'WordPress' => [['wp-content' => '', 'wp-config-sample.php' => '', 'index.php' => ''], 'wordpress'],
    'plain PHP' => [['index.php' => '', 'README.md' => ''], 'php'],
    'plain HTML' => [['index.html' => '', 'styles.css' => ''], 'html'],
]);

it('turns the build off for plain HTML files', function () {
    expect(detectFrameworkOf(['index.html' => ''])['package_manager'])->toBe('none');
});

it('detects nothing in a repository it does not recognise', function (array $files) {
    expect(detectFrameworkOf($files))->toBeNull();
})->with([
    'a package.json that is not valid JSON' => [['package.json' => '{ not json']],
    'only a readme' => [['README.md' => '']],
]);

it('detects nothing when the repository cannot be read', function () {
    Http::preventStrayRequests();
    Http::fake(['api.github.com/repos/acme/app/contents?ref=main' => Http::response(['message' => 'Not Found'], 404)]);

    $detected = app(FrameworkDetector::class)->detect(SourceControlAccount::factory()->github()->create(), 'acme/app', 'main');

    expect($detected)->toBeNull();
});
