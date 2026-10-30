<?php

namespace App\Services\SiteProvisioning;

use App\Actions\Database\EscapesShell;
use App\Models\Site;
use App\Services\SiteUserNameGenerator;
use App\Services\Ssh\SshConnection;
use App\Services\Ssh\StagesFilesPrivately;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Server-side setup for sites that run as their own Linux user: the user
 * itself (no sudo, home closed to other sites) and a PHP-FPM pool that runs
 * the site's PHP as that user.
 *
 * Home directories are mode 750 and the web user (www-data) is added to
 * each site user's group, so nginx can read the files it serves while other
 * site users can't even list the directory.
 */
class SiteIsolationService
{
    use EscapesShell, StagesFilesPrivately;

    public function __construct(
        private SiteUserNameGenerator $names,
    ) {}

    /**
     * The comment (GECOS field) written on every site user, so FlitOps can
     * tell its own accounts apart from ones that happen to share the name.
     */
    public static function accountComment(string $siteUlid): string
    {
        return "flitops-site {$siteUlid}";
    }

    /**
     * Create the site's Linux user, or reuse it when an earlier attempt to
     * set up this same site already created it.
     *
     * A group with the user's name can outlive its user: `userdel` keeps the
     * group while the web user is still a member, which was the case for
     * sites deleted before deleteUser() removed it. Such a leftover group is
     * reused instead of making useradd fail.
     *
     * @throws RuntimeException when the name belongs to an account or group FlitOps didn't create for a site.
     */
    public function createUser(SshConnection $connection, Site $site): void
    {
        $user = $this->guardedUserName($site->system_user);
        $userEsc = $this->escapeForShell($user);
        $comment = self::accountComment($site->ulid);
        $home = $this->escapeForShell("/home/{$user}");

        $existingComment = trim($connection->exec("getent passwd {$userEsc} | cut -d: -f5"));

        if ($existingComment === '') {
            $groupOption = $this->isLeftoverSiteGroup($connection, $user) ? "--gid {$userEsc}" : '--user-group';

            $connection->exec("sudo useradd --create-home --home-dir {$home} --shell /bin/bash {$groupOption} --comment ".$this->escapeForShell($comment)." {$userEsc}");
        } elseif ($existingComment !== $comment) {
            throw new RuntimeException("A Linux user named \"{$user}\" already exists on this server and wasn't created by FlitOps for this site.");
        }

        $connection->exec("sudo chown {$userEsc}:{$userEsc} {$home} && sudo chmod 750 {$home}");
        $connection->exec('sudo usermod -aG '.$userEsc.' '.$this->escapeForShell((string) config('server.web_user')));
    }

    /**
     * Write the site's PHP-FPM pool for the given PHP version and reload
     * PHP-FPM. The config is checked before the reload, so a broken pool
     * can't take the other sites on that PHP version down with it.
     */
    public function writePool(SshConnection $connection, Site $site, string $phpVersion): void
    {
        $user = $this->guardedUserName($site->system_user);
        $poolPath = $this->escapeForShell($this->poolPath($user, $phpVersion));
        $service = $this->escapeForShell("php{$phpVersion}-fpm");

        $this->installAsRoot($connection, $this->poolConfig($site, $user, $phpVersion), $this->poolPath($user, $phpVersion));

        try {
            $connection->exec('sudo '.$this->escapeForShell("php-fpm{$phpVersion}").' -t 2>&1');
        } catch (\Throwable $e) {
            $connection->exec("sudo rm -f {$poolPath}");

            throw $e;
        }

        $connection->exec("sudo systemctl reload {$service}");
    }

    /**
     * Remove a site's PHP-FPM pool for one PHP version and reload PHP-FPM.
     */
    public function removePool(SshConnection $connection, string $systemUser, string $phpVersion): void
    {
        $user = $this->guardedUserName($systemUser);

        $connection->exec('sudo rm -f '.$this->escapeForShell($this->poolPath($user, $phpVersion)));
        $connection->exec('sudo systemctl reload '.$this->escapeForShell("php{$phpVersion}-fpm"));
    }

    /**
     * Delete a site's Linux user and its home directory (which holds the
     * site's files). Skipped, with a warning, when the account on the server
     * isn't the one FlitOps created for this site.
     */
    public function deleteUser(SshConnection $connection, string $systemUser, string $siteUlid): void
    {
        $user = $this->guardedUserName($systemUser);
        $userEsc = $this->escapeForShell($user);

        $existingComment = trim($connection->exec("getent passwd {$userEsc} | cut -d: -f5"));

        if ($existingComment === '') {
            return;
        }

        if ($existingComment !== self::accountComment($siteUlid)) {
            Log::warning("Not deleting Linux user {$user}: it wasn't created by FlitOps for site {$siteUlid}.");

            return;
        }

        $connection->exec("sudo pkill -KILL -u {$userEsc} || true");
        $connection->exec("sudo userdel --remove {$userEsc} 2>&1");

        // userdel keeps the user's group while the web user is a member of it.
        $connection->exec("if getent group {$userEsc} > /dev/null; then sudo groupdel {$userEsc}; fi");
    }

    /**
     * Whether a group named after the site user exists and looks like one a
     * deleted site left behind: a regular (non-system) group whose only
     * member is the web user. Any other group with that name is refused, as
     * reusing it would let its members read the site's files.
     *
     * @throws RuntimeException when a group with the name exists but isn't a leftover.
     */
    private function isLeftoverSiteGroup(SshConnection $connection, string $user): bool
    {
        $entry = trim($connection->exec('getent group '.$this->escapeForShell($user).' || true'));

        if ($entry === '') {
            return false;
        }

        [, , $gid, $members] = array_pad(explode(':', $entry), 4, '');
        $otherMembers = array_diff(array_filter(explode(',', $members)), [(string) config('server.web_user')]);

        if ((int) $gid < 1000 || $otherMembers !== []) {
            throw new RuntimeException("A Linux group named \"{$user}\" already exists on this server and wasn't left behind by a FlitOps site.");
        }

        return true;
    }

    public function poolPath(string $systemUser, string $phpVersion): string
    {
        return "/etc/php/{$phpVersion}/fpm/pool.d/{$systemUser}.conf";
    }

    /**
     * `ondemand` starts PHP workers only when requests arrive and stops them
     * once idle, so a server with many quiet sites doesn't keep a set of
     * idle PHP processes alive for each one.
     */
    private function poolConfig(Site $site, string $user, string $phpVersion): string
    {
        $webUser = config('server.web_user');

        return <<<CONF
[{$user}]
user = {$user}
group = {$user}
listen = {$site->phpFpmSocket($phpVersion)}
listen.owner = {$webUser}
listen.group = {$webUser}
listen.mode = 0660
pm = ondemand
pm.max_children = 5
pm.process_idle_timeout = 10s
pm.max_requests = 500

CONF;
    }

    /**
     * Every command here acts on a site user, so refuse anything that could
     * point it at the server user or a system account instead.
     *
     * @throws RuntimeException
     */
    private function guardedUserName(?string $systemUser): string
    {
        if ($systemUser === null
            || ! preg_match('/^[a-z][a-z0-9_]{0,'.(SiteUserNameGenerator::MAX_LENGTH - 1).'}$/', $systemUser)
            || $this->names->isReserved($systemUser)) {
            throw new RuntimeException('Refusing to manage Linux user "'.($systemUser ?? '').'": it is not a site user.');
        }

        return $systemUser;
    }
}
