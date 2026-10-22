<?php

namespace App\Services\SiteProvisioning;

use App\Actions\Database\EscapesShell;
use App\Actions\Database\KeepsSecretsOffCommandLine;
use App\Models\Site;
use App\Services\SiteUserNameGenerator;
use App\Services\Ssh\SshConnection;
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
    use EscapesShell, KeepsSecretsOffCommandLine;

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
     * @throws RuntimeException when the name belongs to an account FlitOps didn't create for this site.
     */
    public function createUser(SshConnection $connection, Site $site): void
    {
        $user = $this->guardedUserName($site->system_user);
        $userEsc = $this->escapeForShell($user);
        $comment = self::accountComment($site->ulid);
        $home = $this->escapeForShell("/home/{$user}");

        $existingComment = trim($connection->exec("getent passwd {$userEsc} | cut -d: -f5"));

        if ($existingComment === '') {
            $connection->exec("sudo useradd --create-home --home-dir {$home} --shell /bin/bash --user-group --comment ".$this->escapeForShell($comment)." {$userEsc}");
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

        $this->withPrivateDirectory($connection, function (string $directory) use ($connection, $site, $user, $phpVersion, $poolPath): void {
            $staged = "{$directory}/pool.conf";

            $connection->upload($this->poolConfig($site, $user, $phpVersion), $staged);
            $connection->exec('sudo install -o root -g root -m 644 '.$this->escapeForShell($staged)." {$poolPath}");
        });

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
