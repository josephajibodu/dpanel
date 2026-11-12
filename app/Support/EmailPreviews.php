<?php

namespace App\Support;

use App\Models\Backup;
use App\Models\Server;
use App\Models\ServerDatabase;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\SiteIncident;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Notifications\DatabaseBackupFailed;
use App\Notifications\ServerProvisioningFailed;
use App\Notifications\SiteRecovered;
use App\Notifications\SiteWebServerSyncFailed;
use App\Notifications\SiteWentDown;
use App\Notifications\SslCertificateExpiring;
use App\Notifications\SslCertificateRequestFailed;
use App\Notifications\TeamInvitationNotification;
use App\Notifications\Welcome;
use App\Notifications\WildcardCertificateExpiring;
use App\Notifications\WildcardCertificateRenewalFailed;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Every email the app sends, built from made-up sample data, for the
 * local-only preview page at /dev/emails. Nothing here reads or writes the
 * database. Add a new email to all() when you create one.
 */
class EmailPreviews
{
    /**
     * @return array<string, array{group: string, name: string, notification: Notification}>
     */
    public static function all(): array
    {
        $team = self::team();
        $server = self::server($team);
        $site = self::site($server);

        return [
            'verify-email' => [
                'group' => 'Account',
                'name' => 'Confirm email address',
                'notification' => new VerifyEmail,
            ],
            'welcome' => [
                'group' => 'Account',
                'name' => 'Welcome',
                'notification' => new Welcome,
            ],
            'reset-password' => [
                'group' => 'Account',
                'name' => 'Reset password',
                'notification' => new ResetPassword('sample-reset-token'),
            ],
            'team-invitation' => [
                'group' => 'Account',
                'name' => 'Team invitation',
                'notification' => new TeamInvitationNotification(
                    (new TeamInvitation)
                        ->forceFill(['id' => 1, 'email' => 'new.member@example.com', 'token' => 'sample-invitation-token'])
                        ->setRelation('team', $team),
                ),
            ],
            'server-provisioning-failed' => [
                'group' => 'Servers',
                'name' => 'Server provisioning failed',
                'notification' => new ServerProvisioningFailed(
                    $server,
                    'Timed out waiting for the server to accept SSH connections after 10 minutes.',
                ),
            ],
            'site-went-down' => [
                'group' => 'Sites',
                'name' => 'Site down',
                'notification' => new SiteWentDown(self::incident($site), [
                    '2026/11/12 09:41:07 [error] 1432#1432: *8812 connect() to unix:/run/php/php8.4-fpm.sock failed (111: Connection refused) while connecting to upstream, request: "GET / HTTP/2.0"',
                    '2026/11/12 09:41:09 [error] 1432#1432: *8815 connect() to unix:/run/php/php8.4-fpm.sock failed (111: Connection refused) while connecting to upstream, request: "GET /checkout HTTP/2.0"',
                ]),
            ],
            'site-recovered' => [
                'group' => 'Sites',
                'name' => 'Site recovered',
                'notification' => new SiteRecovered(self::incident($site, resolved: true)),
            ],
            'site-web-server-sync-failed' => [
                'group' => 'Sites',
                'name' => 'Web server config not applied',
                'notification' => new SiteWebServerSyncFailed(
                    $site,
                    'nginx: [emerg] unknown directive "proxy_pas" in /etc/nginx/sites-enabled/shop.example.com:42',
                ),
            ],
            'ssl-certificate-expiring' => [
                'group' => 'Certificates',
                'name' => 'SSL certificate expiring soon',
                'notification' => new SslCertificateExpiring(self::domain($site, expiresInDays: 6)),
            ],
            'ssl-certificate-expired' => [
                'group' => 'Certificates',
                'name' => 'SSL certificate expired',
                'notification' => new SslCertificateExpiring(self::domain($site, expiresInDays: -2)),
            ],
            'ssl-certificate-request-failed' => [
                'group' => 'Certificates',
                'name' => 'SSL certificate request failed',
                'notification' => new SslCertificateRequestFailed(
                    self::domain($site, expiresInDays: 6),
                    'DNS problem: NXDOMAIN looking up A for shop.example.com. Check that a DNS record exists for this domain.',
                ),
            ],
            'wildcard-certificate-expiring' => [
                'group' => 'Certificates',
                'name' => 'Wildcard certificate expiring soon',
                'notification' => new WildcardCertificateExpiring('*.flitops.xyz', now()->addDays(9)),
            ],
            'wildcard-certificate-stale' => [
                'group' => 'Certificates',
                'name' => 'Wildcard certificate expired on one server',
                'notification' => new WildcardCertificateExpiring('*.flitops.xyz', now()->subDay(), 'demo-shop.flitops.xyz'),
            ],
            'wildcard-certificate-renewal-failed' => [
                'group' => 'Certificates',
                'name' => 'Wildcard certificate renewal failed',
                'notification' => new WildcardCertificateRenewalFailed(
                    '*.flitops.xyz',
                    'The DNS provider rejected the API token (403 Forbidden).',
                ),
            ],
            'database-backup-failed' => [
                'group' => 'Backups',
                'name' => 'Database backup failed',
                'notification' => new DatabaseBackupFailed(self::backup($server)),
            ],
        ];
    }

    /**
     * The message a preview would send, addressed to the sample user.
     */
    public static function message(string $key): ?MailMessage
    {
        $preview = self::all()[$key] ?? null;

        return $preview ? $preview['notification']->toMail(self::user()) : null;
    }

    private static function team(): Team
    {
        return (new Team)->forceFill(['id' => 1, 'name' => 'Acme Studio', 'slug' => 'acme-studio']);
    }

    private static function user(): User
    {
        return (new User)
            ->forceFill(['id' => 1, 'name' => 'Ada Okafor', 'email' => 'ada@example.com'])
            ->setRelation('currentTeam', self::team());
    }

    private static function server(Team $team): Server
    {
        return (new Server)
            ->forceFill(['id' => 1, 'ulid' => '01jsamplepreviewserver00001', 'name' => 'production-web-1', 'web_server' => 'nginx'])
            ->setRelation('team', $team);
    }

    private static function site(Server $server): Site
    {
        return (new Site)
            ->forceFill(['id' => 1, 'ulid' => '01jsamplepreviewsite0000001', 'domain' => 'shop.example.com'])
            ->setRelation('server', $server);
    }

    private static function domain(Site $site, int $expiresInDays): SiteDomain
    {
        return (new SiteDomain)
            ->forceFill(['id' => 1, 'hostname' => 'shop.example.com', 'ssl_expires_at' => now()->addDays($expiresInDays)])
            ->setRelation('site', $site);
    }

    private static function incident(Site $site, bool $resolved = false): SiteIncident
    {
        return (new SiteIncident)
            ->forceFill([
                'id' => 1,
                'reason' => 'The site returned HTTP 502 (Bad Gateway).',
                'started_at' => now()->subMinutes(14),
                'resolved_at' => $resolved ? now() : null,
            ])
            ->setRelation('site', $site);
    }

    private static function backup(Server $server): Backup
    {
        $database = (new ServerDatabase)
            ->forceFill(['id' => 1, 'name' => 'shop_production'])
            ->setRelation('server', $server);

        return (new Backup)
            ->forceFill([
                'id' => 1,
                'server_database_id' => 1,
                'error_message' => 'The storage provider refused the upload: the access key is no longer valid.',
            ])
            ->setRelation('serverDatabase', $database);
    }
}
