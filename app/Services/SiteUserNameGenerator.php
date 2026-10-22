<?php

namespace App\Services;

use App\Models\Server;

/**
 * Picks the Linux user name an isolated site runs as, derived from its
 * domain (example.com → example_com), unique on its server and never one
 * of the accounts Ubuntu or FlitOps already rely on.
 */
class SiteUserNameGenerator
{
    /**
     * Linux limits user names to 32 characters.
     */
    public const MAX_LENGTH = 32;

    /**
     * Accounts that exist on a provisioned Ubuntu server (or that FlitOps
     * installs) and must never be handed to a site.
     *
     * @var list<string>
     */
    private const RESERVED = [
        'root', 'daemon', 'bin', 'sys', 'sync', 'games', 'man', 'lp', 'mail', 'news', 'uucp',
        'proxy', 'www_data', 'www', 'backup', 'list', 'irc', 'gnats', 'nobody', 'nogroup',
        'systemd_network', 'systemd_resolve', 'systemd_timesync', 'systemd_coredump',
        'messagebus', 'syslog', 'sshd', 'uuidd', 'tcpdump', 'tss', 'landscape', 'pollinate',
        'fwupd_refresh', 'lxd', 'ubuntu', 'admin', 'sudo', 'mysql', 'postgres', 'redis',
        'memcache', 'supervisor', 'nginx', 'ftp', 'git',
    ];

    /**
     * Accounts that exist on the server but aren't in this list are caught
     * when the site is set up: FlitOps refuses to take over a Linux user it
     * didn't create for that site (see SiteIsolationService::createUser()).
     */
    public function generate(Server $server, string $domain): string
    {
        $base = $this->baseName($domain);
        $taken = $server->sites()->whereNotNull('system_user')->pluck('system_user')->all();

        $candidate = $base;
        $suffix = 1;

        while ($this->isReserved($candidate) || in_array($candidate, $taken, true)) {
            $suffix++;
            $ending = '_'.$suffix;
            $candidate = substr($base, 0, self::MAX_LENGTH - strlen($ending)).$ending;
        }

        return $candidate;
    }

    public function isReserved(string $name): bool
    {
        return in_array($name, self::RESERVED, true)
            || $name === config('server.user')
            || $name === str_replace('-', '_', (string) config('server.web_user'));
    }

    /**
     * Lowercase, swap anything outside a-z/0-9 for "_", make sure it starts
     * with a letter, and cut it to the length Linux allows.
     */
    private function baseName(string $domain): string
    {
        $name = preg_replace('/[^a-z0-9]/', '_', strtolower($domain));

        if (! preg_match('/^[a-z]/', $name)) {
            $name = 's_'.$name;
        }

        return rtrim(substr($name, 0, self::MAX_LENGTH), '_') ?: 's_site';
    }
}
