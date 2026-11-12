<?php

use App\Enums\ProjectType;
use App\Models\Site;
use App\Services\Deployment\ZeroDowntimeDeploymentStrategy;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * Run a fragment of the deploy script against a release folder holding the
 * given folders, the way a deploy runs it.
 *
 * @param  list<string>  $folders
 */
function runInRelease(string $script, array $folders): Process
{
    $release = sys_get_temp_dir().'/strategy-release-'.bin2hex(random_bytes(4));

    foreach ($folders as $folder) {
        mkdir("{$release}/{$folder}", recursive: true);
    }

    $run = new Process(['/bin/bash', '-c', "set -e\nRELEASE_PATH='{$release}'\nBRANCH='main'\n{$script}"]);
    $run->run();

    File::deleteDirectory($release);

    return $run;
}

it('runs the deploy script inside the app folder of a site whose app is in a subfolder', function () {
    $site = Site::factory()->isolated()->make(['project_type' => ProjectType::StaticHtml, 'app_directory' => 'frontend']);

    $run = runInRelease((new ZeroDowntimeDeploymentStrategy)->prepend($site).'pwd', ['frontend', 'backend']);

    expect($run->getExitCode())->toBe(0)
        ->and(trim($run->getOutput()))->toEndWith('/frontend');
});

it('stops the deploy with a plain message when the repository lacks the app folder', function () {
    $site = Site::factory()->isolated()->make(['project_type' => ProjectType::StaticHtml, 'app_directory' => 'frontend']);

    $run = runInRelease((new ZeroDowntimeDeploymentStrategy)->prepend($site).'echo built', ['backend']);

    expect($run->getExitCode())->toBe(1)
        ->and(trim($run->getOutput()))->toBe("[ERROR] The app folder 'frontend' is not in the repository on branch main.");
});

it('refuses to make a release live when it lacks the app folder, as on a rollback', function () {
    $site = Site::factory()->isolated()->make(['project_type' => ProjectType::StaticHtml, 'app_directory' => 'frontend']);

    // `echo` stands in for `ln`, so the test shows whether the swap was reached.
    $run = runInRelease("ln() { echo swapped; }\n".(new ZeroDowntimeDeploymentStrategy)->activate($site), ['backend']);

    expect($run->getExitCode())->toBe(1)
        ->and($run->getOutput())->not->toContain('swapped');
});

it('points current at the app folder inside the release', function () {
    $site = Site::factory()->isolated()->make(['project_type' => ProjectType::StaticHtml, 'app_directory' => 'frontend']);

    $run = runInRelease('ln() { echo "ln $*"; }'."\n".(new ZeroDowntimeDeploymentStrategy)->activate($site), ['frontend']);

    expect(trim($run->getOutput()))->toMatch('#^ln -sfn \S+/frontend '.preg_quote($site->currentPath(), '#').'$#');
});
