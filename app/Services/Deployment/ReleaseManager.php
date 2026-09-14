<?php

namespace App\Services\Deployment;

use App\Models\Site;
use App\Services\Ssh\SshConnection;

class ReleaseManager
{
    /**
     * Delete releases beyond the newest $keep, always preserving whichever
     * release `current` points at even if it has fallen out of that window
     * (e.g. after a rollback to an older release) — a rollback must never
     * have its target pruned out from under it.
     */
    public function pruneOldReleases(SshConnection $connection, Site $site, int $keep): void
    {
        $releasesPath = $site->releasesPath();

        $listing = trim($connection->exec("ls -1 {$releasesPath} 2>/dev/null || true"));

        if ($listing === '') {
            return;
        }

        $releases = array_values(array_filter(explode("\n", $listing)));

        // ULIDs (and the literal "bootstrap" folder) sort lexicographically in
        // creation order, so a descending sort puts the newest releases first.
        rsort($releases);

        $keepSet = array_slice($releases, 0, $keep);

        $currentRelease = $this->currentReleaseName($connection, $site);

        if ($currentRelease !== null && ! in_array($currentRelease, $keepSet, true)) {
            $keepSet[] = $currentRelease;
        }

        $toDelete = array_diff($releases, $keepSet);

        if ($toDelete === []) {
            return;
        }

        $paths = implode(' ', array_map(
            fn (string $release) => escapeshellarg("{$releasesPath}/{$release}"),
            $toDelete
        ));

        $connection->exec("rm -rf {$paths}");
    }

    private function currentReleaseName(SshConnection $connection, Site $site): ?string
    {
        $resolved = trim($connection->exec("readlink -f {$site->currentPath()} 2>/dev/null || true"));

        return $resolved !== '' ? basename($resolved) : null;
    }
}
