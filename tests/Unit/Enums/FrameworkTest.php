<?php

use App\Enums\Framework;
use App\Enums\ProjectType;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * Run a deploy script the way a deploy does, with only the given folder of
 * stand-in commands on the PATH and a throwaway home folder.
 *
 * @param  array<string, string>  $commands  Command name => the bash it runs.
 */
function runDeployScript(string $script, array $commands): Process
{
    $sandbox = sys_get_temp_dir().'/framework-deploy-script-'.bin2hex(random_bytes(4));
    mkdir("{$sandbox}/bin", recursive: true);
    mkdir("{$sandbox}/home");

    foreach ($commands as $name => $body) {
        file_put_contents("{$sandbox}/bin/{$name}", "#!/bin/bash\n{$body}\n");
        chmod("{$sandbox}/bin/{$name}", 0755);
    }

    $deploy = new Process(['/bin/bash', '-c', "set -e\n{$script}"], "{$sandbox}/home", ['PATH' => "{$sandbox}/bin", 'HOME' => "{$sandbox}/home"]);
    $deploy->run();

    (new Filesystem)->deleteDirectory($sandbox);

    return $deploy;
}

it('has a framework for every project type', function (ProjectType $projectType) {
    expect(Framework::fromProjectType($projectType)->projectType())->toBe($projectType);
})->with(ProjectType::cases());

it('installs packages and runs the build command for a site built with npm', function () {
    expect(Framework::React->defaultDeployScript('npm', 'npm run build'))
        ->toBe("if [ -f package-lock.json ]; then npm ci; else npm install; fi\n\nnpm run build");
});

it('installs a package manager the server lacks into the deploying user\'s home, then builds with it', function (string $packageManager) {
    // Stands in for npm: "installs" the package by leaving a command of that
    // name in <prefix>/bin, where the real npm would put it.
    $npm = <<<'BASH'
    echo "npm $*"
    /bin/mkdir -p "$4/bin"
    printf '#!/bin/bash\necho "%s $*"\n' "$5" > "$4/bin/$5"
    /bin/chmod +x "$4/bin/$5"
    BASH;

    $deploy = runDeployScript(
        Framework::Vue->defaultDeployScript($packageManager, "{$packageManager} run build"),
        ['npm' => $npm],
    );

    expect($deploy->getExitCode())->toBe(0)
        ->and($deploy->getOutput())->toMatch("/^npm install --global --prefix \S+\/home\/\.local {$packageManager}$/m")
        ->and($deploy->getOutput())->toContain("{$packageManager} install --frozen-lockfile\n{$packageManager} run build");
})->with(['pnpm', 'yarn', 'bun']);

it('does not install a package manager the server already has', function () {
    $deploy = runDeployScript(
        Framework::Vue->defaultDeployScript('pnpm', 'pnpm run build'),
        ['pnpm' => 'echo "pnpm $*"', 'npm' => 'echo "npm $*"'],
    );

    expect($deploy->getOutput())->toBe("pnpm install --frozen-lockfile\npnpm run build\n");
});

it('has nothing to run for static files without a package manager or build command', function (Framework $framework, ?string $packageManager, ?string $buildCommand) {
    expect($framework->defaultDeployScript($packageManager, $buildCommand))->toBe('');
})->with([
    'no package manager' => [Framework::StaticHtml, 'none', 'npm run build'],
    'nothing chosen' => [Framework::StaticHtml, null, null],
    'blank build command' => [Framework::React, 'npm', '  '],
]);

it('keeps the project type\'s deploy script for a PHP framework, whatever the package manager', function () {
    expect(Framework::Laravel->defaultDeployScript('pnpm', 'pnpm run build'))
        ->toBe(ProjectType::Laravel->defaultDeployScript());
});
