<?php

namespace App\Services\Deployment;

use App\Models\Site;

/**
 * Every deploy clones into a fresh releases/{ulid} directory and atomically
 * swaps the `current` symlink onto it once the build succeeds, instead of
 * mutating the site's live checkout in place. This is what makes deploys
 * zero-downtime and rollback possible (RollbackSiteJob reuses activate() to
 * point `current` back at an older release without rebuilding anything).
 */
class ZeroDowntimeDeploymentStrategy implements DeploymentStrategy
{
    public function prepend(Site $site): string
    {
        $enterApp = [$this->appFolderCheck($site), "cd \"{$this->appPath($site)}\"", $this->symlinkSharedPaths($site)];

        $lines = $site->isIsolated()
            ? $enterApp
            : ['mkdir -p "$RELEASE_PATH"', $this->cloneCommand($site), ...$enterApp];

        return implode("\n", array_filter($lines, fn (string $line) => $line !== ''))."\n";
    }

    public function append(Site $site): string
    {
        $app = $this->appPath($site);

        if ($site->isIsolated()) {
            $storageChmod = "if [ -d \"{$app}/storage\" ]; then chmod -R 775 \"{$app}/storage\"; fi";
            $cacheChmod = "if [ -d \"{$app}/bootstrap/cache\" ]; then chmod -R 775 \"{$app}/bootstrap/cache\"; fi";

            return "\n{$storageChmod}\n{$cacheChmod}\n".$this->activate($site);
        }

        $chown = 'sudo chown -R $SERVER_USER:$WEB_USER "$RELEASE_PATH"';
        $storageChmod = "if [ -d \"{$app}/storage\" ]; then sudo chmod -R 775 \"{$app}/storage\"; fi";
        $cacheChmod = "if [ -d \"{$app}/bootstrap/cache\" ]; then sudo chmod -R 775 \"{$app}/bootstrap/cache\"; fi";

        return "\n{$chown}\n{$storageChmod}\n{$cacheChmod}\n".$this->activate($site);
    }

    public function activate(Site $site): string
    {
        $currentPath = $site->currentPath();
        $app = $this->appPath($site);

        // Checked here as well as before the build, because a rollback only
        // runs this part and must not point `current` at a missing folder.
        $swap = ltrim($this->appFolderCheck($site)."\nln -sfn \"{$app}\" {$currentPath}");

        $script = $site->isIsolated() ? $swap : "{$swap}\n".$this->reloadPhpFpm($site);

        $postActivation = $site->project_type->postActivationScript();

        if ($postActivation !== '') {
            $script .= "\ncd \"{$app}\"\n{$postActivation}";
        }

        return $script;
    }

    /**
     * The app's folder inside the release being built, as the deploy
     * script writes it: the release itself unless the site's app lives in
     * a subfolder of the repository.
     */
    private function appPath(Site $site): string
    {
        return $site->appPathIn('$RELEASE_PATH');
    }

    /**
     * Stops with a plain message when the repository doesn't have the
     * site's app directory, instead of a bare "No such file or directory".
     * Empty for a site whose app is at the top of the repository.
     */
    private function appFolderCheck(Site $site): string
    {
        if (! $site->app_directory) {
            return '';
        }

        return "if [ ! -d \"{$this->appPath($site)}\" ]; then echo \"[ERROR] The app folder '{$site->app_directory}' is not in the repository on branch \$BRANCH.\"; exit 1; fi";
    }

    /**
     * Reloads are serialised with a lock file in /run, where only root can
     * create files, so a site's user can't pre-create the lock, hold it or
     * swap it for a link the way it could in the shared /tmp.
     */
    public function reloadPhpFpm(Site $site): string
    {
        return "echo 'Restarting FPM...'\nsudo -S flock -w 10 /run/flitops-php-fpm.lock service \"\$PHP_FPM\" reload";
    }

    /**
     * The site's user never holds a git key: FlitOps clones as the server
     * user into a private temporary directory, then streams the files to
     * the site user, who unpacks them into the release directory.
     */
    public function cloneForIsolatedSite(Site $site): string
    {
        $unpack = $site->commandAsSystemUser('mkdir -p "$1" && tar -C "$1" -xf -').' _ "$RELEASE_PATH"';

        return implode("\n", [
            'CLONE_DIR=$(mktemp -d)',
            'trap \'rm -rf "$CLONE_DIR"\' EXIT',
            $this->gitCloneCommand($site, '"$CLONE_DIR/release"'),
            'tar -C "$CLONE_DIR/release" -cf - . | '.$unpack,
        ])."\n";
    }

    private function cloneCommand(Site $site): string
    {
        $gitCommand = $this->gitCloneCommand($site, '"$RELEASE_PATH"');

        return "{$gitCommand}\ngit -C \"\$RELEASE_PATH\" config --global --add safe.directory \"\$RELEASE_PATH\"";
    }

    /**
     * Clone with the server user's key, which is the one added to the
     * source control account.
     */
    private function gitCloneCommand(Site $site, string $target): string
    {
        $serverUser = config('server.user');
        $sshKeyPath = "/home/{$serverUser}/.ssh/id_ed25519";
        $repoUrl = $site->gitCloneUrl();

        return "GIT_SSH_COMMAND='ssh -i {$sshKeyPath} -o StrictHostKeyChecking=accept-new' git clone --branch {$site->branch} {$repoUrl} {$target}";
    }

    /**
     * Symlink every shared file/directory this project type needs (env
     * files, writable storage, uploads, ...) into the freshly cloned
     * release. Optional targets that were never created (e.g. the sqlite
     * file when the site uses a real server database instead) are skipped
     * rather than left as dangling symlinks, but a missing env file stops
     * the deploy: without it the framework silently falls back to its
     * defaults and fails later with a misleading error.
     *
     * Whatever the repository committed at a shared path is removed first,
     * since `ln -sfn` onto an existing directory would create the link
     * inside it instead of replacing it.
     */
    private function symlinkSharedPaths(Site $site): string
    {
        $symlinks = $site->project_type->sharedSymlinks();

        if ($symlinks === []) {
            return '';
        }

        $sharedPath = rtrim($site->sharedPath(), '/');
        $app = $this->appPath($site);
        $lines = [];

        foreach ($symlinks as $releaseRelative => $sharedRelative) {
            $sharedAbsolute = "{$sharedPath}/{$sharedRelative}";
            $releaseDir = dirname($releaseRelative);

            if ($releaseDir !== '.') {
                $lines[] = "mkdir -p \"{$app}/{$releaseDir}\"";
            }

            if (str_starts_with($releaseRelative, '.env')) {
                $lines[] = "if [ ! -e '{$sharedAbsolute}' ]; then echo \"[ERROR] {$sharedAbsolute} is missing. Save the site's environment before deploying.\"; exit 1; fi";
            }

            $lines[] = "if [ -e '{$sharedAbsolute}' ]; then rm -rf \"{$app}/{$releaseRelative}\" && ln -sfn '{$sharedAbsolute}' \"{$app}/{$releaseRelative}\"; fi";
        }

        return implode("\n", $lines);
    }
}
