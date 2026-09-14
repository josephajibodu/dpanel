<?php

use App\Models\Site;
use App\Services\Deployment\ReleaseManager;
use App\Services\Ssh\SshConnection;

function releaseManagerSite(): Site
{
    return Site::factory()->make(['domain' => 'example.com']);
}

/**
 * @param  list<string>  $releases
 * @param  list<string>  &$deletedCommands  Captures every `rm -rf` command issued.
 */
function mockReleasesListing(array $releases, ?string $currentRelease, array &$deletedCommands = []): SshConnection
{
    $connection = Mockery::mock(SshConnection::class);

    $connection->shouldReceive('exec')->andReturnUsing(function (string $command) use ($releases, $currentRelease, &$deletedCommands) {
        if (str_starts_with($command, 'ls -1')) {
            return implode("\n", $releases);
        }

        if (str_starts_with($command, 'readlink -f')) {
            return $currentRelease ? "/home/artisan/example.com/releases/{$currentRelease}" : '';
        }

        if (str_starts_with($command, 'rm -rf')) {
            $deletedCommands[] = $command;
        }

        return '';
    });

    return $connection;
}

it('keeps only the newest N releases', function () {
    $site = releaseManagerSite();
    $releases = ['01AAA', '01BBB', '01CCC', '01DDD', '01EEE'];
    $deleted = [];
    $connection = mockReleasesListing($releases, currentRelease: '01EEE', deletedCommands: $deleted);

    (new ReleaseManager)->pruneOldReleases($connection, $site, keep: 2);

    expect($deleted)->toHaveCount(1);
    // Newest two (01EEE, 01DDD) are kept; the rest are deleted.
    expect($deleted[0])->toContain('01AAA')
        ->and($deleted[0])->toContain('01BBB')
        ->and($deleted[0])->toContain('01CCC')
        ->and($deleted[0])->not->toContain('01DDD')
        ->and($deleted[0])->not->toContain('01EEE');
});

it('never prunes the release current points at even if it has fallen out of the retention window', function () {
    $site = releaseManagerSite();
    $releases = ['01AAA', '01BBB', '01CCC', '01DDD', '01EEE'];
    $deleted = [];
    // Rolled back to the oldest release — it must survive pruning regardless of age.
    $connection = mockReleasesListing($releases, currentRelease: '01AAA', deletedCommands: $deleted);

    (new ReleaseManager)->pruneOldReleases($connection, $site, keep: 2);

    expect($deleted)->toHaveCount(1)
        ->and($deleted[0])->not->toContain('01AAA')
        ->and($deleted[0])->toContain('01BBB')
        ->and($deleted[0])->toContain('01CCC');
});

it('does nothing when releases are within the retention window', function () {
    $site = releaseManagerSite();
    $deleted = [];
    $connection = mockReleasesListing(['01AAA', '01BBB'], currentRelease: '01BBB', deletedCommands: $deleted);

    (new ReleaseManager)->pruneOldReleases($connection, $site, keep: 5);

    expect($deleted)->toBeEmpty();
});

it('does nothing when the releases directory is empty', function () {
    $site = releaseManagerSite();
    $deleted = [];
    $connection = mockReleasesListing([], currentRelease: null, deletedCommands: $deleted);

    (new ReleaseManager)->pruneOldReleases($connection, $site, keep: 5);

    expect($deleted)->toBeEmpty();
});
