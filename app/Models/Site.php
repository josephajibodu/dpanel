<?php

namespace App\Models;

use App\Actions\Database\EscapesShell;
use App\Enums\DeploymentStatus;
use App\Enums\ProjectType;
use App\Enums\RepositoryProvider;
use App\Enums\SiteProvisioningStep;
use App\Enums\SiteStatus;
use App\Enums\UptimeStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Site extends Model
{
    use EscapesShell;

    /** @use HasFactory<\Database\Factories\SiteFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'server_id',
        'source_control_account_id',
        'domain',
        'root_path',
        'system_user',
        'site_name',
        'aliases',
        'directory',
        'repository',
        'repository_provider',
        'branch',
        'project_type',
        'server_database_id',
        'php_version',
        'package_manager',
        'build_command',
        'status',
        'error_message',
        'uptime_status',
        'uptime_checked_at',
        'uptime_consecutive_failures',
        'provisioning_step',
        'deploy_key_id',
        'webhook_secret',
        'auto_deploy',
        'deployment_started_at',
        'deployment_finished_at',
        'env_content',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'webhook_secret',
        'env_content',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SiteStatus::class,
            'provisioning_step' => SiteProvisioningStep::class,
            'project_type' => ProjectType::class,
            'repository_provider' => RepositoryProvider::class,
            'aliases' => 'array',
            'auto_deploy' => 'boolean',
            'deployment_started_at' => 'datetime',
            'deployment_finished_at' => 'datetime',
            'env_content' => 'encrypted',
            'uptime_status' => UptimeStatus::class,
            'uptime_checked_at' => 'datetime',
        ];
    }

    /**
     * Whether this site runs as its own Linux user. Sites created before
     * isolation existed (or with it turned off) have no user of their own
     * and run as the server user.
     */
    public function isIsolated(): bool
    {
        return $this->system_user !== null;
    }

    /**
     * The Linux user that owns this site's files and runs its PHP, deploys,
     * workers and cron jobs.
     */
    public function systemUser(): string
    {
        return $this->system_user ?? config('server.user');
    }

    /**
     * Wrap a shell command so it runs as this site's own user. FlitOps
     * connects as the server user, so isolated sites need the switch;
     * other sites already run as the server user and get the command back
     * unchanged.
     */
    public function commandAsSystemUser(string $command): string
    {
        if (! $this->isIsolated()) {
            return $command;
        }

        return 'sudo -u '.$this->escapeForShell($this->system_user).' -H bash -c '.$this->escapeForShell($command);
    }

    /**
     * The PHP-FPM socket nginx hands this site's PHP requests to.
     */
    public function phpFpmSocket(string $phpVersion): string
    {
        return "/run/php/php{$phpVersion}-fpm-{$this->systemUser()}.sock";
    }

    /**
     * The folder certbot writes Let's Encrypt challenge files into and nginx
     * serves them from. Isolated sites use a folder only root controls, so
     * certbot (running as root) never writes into a folder the site's user
     * can redirect; other sites keep using their web root.
     */
    public function acmeChallengeRoot(): string
    {
        return $this->isIsolated() ? config('server.acme_challenge_path') : $this->webRoot();
    }

    /**
     * Get the root path for this site on the server.
     */
    public function rootPath(): string
    {
        $segment = $this->root_path ?? $this->domain;

        return "/home/{$this->systemUser()}/{$segment}";
    }

    /**
     * Get the full web root path including the directory.
     *
     * Resolves through the `current` release symlink, so this always points
     * at whatever release is actively serving traffic.
     */
    public function webRoot(): string
    {
        $directory = $this->directory ?: '/';

        return rtrim($this->currentPath(), '/').'/'.ltrim($directory, '/');
    }

    /**
     * Directory holding every release this site has ever deployed.
     */
    public function releasesPath(): string
    {
        return $this->rootPath().'/releases';
    }

    /**
     * Directory holding files persisted across releases (.env, storage, etc).
     */
    public function sharedPath(): string
    {
        return $this->rootPath().'/shared';
    }

    /**
     * Absolute path of the SQLite database file sites without a server
     * database fall back to. Lives in shared/ so it persists across releases.
     */
    public function sqliteDatabasePath(): string
    {
        return $this->sharedPath().'/database/database.sqlite';
    }

    /**
     * Whether this site stores its data in the shared SQLite file rather
     * than a server database.
     */
    public function usesSqlite(): bool
    {
        return $this->project_type === ProjectType::Laravel && $this->server_database_id === null;
    }

    /**
     * Path to the `current` symlink, which points at the active release.
     */
    public function currentPath(): string
    {
        return $this->rootPath().'/current';
    }

    /**
     * Build the authenticated SSH clone URL for this site's repository, in
     * the same form used both at provisioning time and at deploy time.
     */
    public function gitCloneUrl(): ?string
    {
        if (! $this->repository) {
            return null;
        }

        return match ($this->repository_provider?->value) {
            'github' => "git@github.com:{$this->repository}.git",
            'gitlab' => "git@gitlab.com:{$this->repository}.git",
            'bitbucket' => "git@bitbucket.org:{$this->repository}.git",
            default => $this->repository,
        };
    }

    /**
     * Get the repository URL.
     */
    public function repositoryUrl(): ?string
    {
        if (! $this->repository) {
            return null;
        }

        $baseUrl = $this->repository_provider?->baseUrl();

        if (! $baseUrl) {
            return $this->repository;
        }

        return "{$baseUrl}/{$this->repository}";
    }

    /**
     * Get short repository name (owner/repo format).
     */
    public function shortRepository(): ?string
    {
        return $this->repository;
    }

    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function sourceControlAccount(): BelongsTo
    {
        return $this->belongsTo(SourceControlAccount::class);
    }

    public function serverDatabase(): BelongsTo
    {
        return $this->belongsTo(ServerDatabase::class);
    }

    public function backupSchedule(): HasOne
    {
        return $this->hasOne(BackupSchedule::class);
    }

    public function backups(): HasMany
    {
        return $this->hasMany(Backup::class);
    }

    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class);
    }

    public function latestDeployment(): HasOne
    {
        return $this->hasOne(Deployment::class)->latestOfMany();
    }

    public function environmentVariables(): HasMany
    {
        return $this->hasMany(EnvironmentVariable::class);
    }

    public function deployScript(): HasOne
    {
        return $this->hasOne(DeployScript::class);
    }

    public function commandRuns(): HasMany
    {
        return $this->hasMany(SiteCommandRun::class);
    }

    /**
     * @return HasMany<SiteDomain, $this>
     */
    public function domains(): HasMany
    {
        return $this->hasMany(SiteDomain::class);
    }

    /**
     * @return HasOne<SiteDomain, $this>
     */
    public function primaryDomain(): HasOne
    {
        return $this->hasOne(SiteDomain::class)->where('is_primary', true);
    }

    /**
     * @return HasMany<SiteIncident, $this>
     */
    public function incidents(): HasMany
    {
        return $this->hasMany(SiteIncident::class);
    }

    /**
     * The incident for the site's current downtime, if it's down.
     *
     * @return HasOne<SiteIncident, $this>
     */
    public function openIncident(): HasOne
    {
        return $this->hasOne(SiteIncident::class)->whereNull('resolved_at');
    }

    public function nginxFiles(): HasMany
    {
        return $this->hasMany(SiteNginxFile::class);
    }

    public function nginxHistory(): HasMany
    {
        return $this->hasMany(SiteNginxHistory::class)->latest('created_at');
    }

    public function nginxSnippetsBasePath(): string
    {
        return "/etc/flitops/sites/{$this->ulid}/nginx";
    }

    /**
     * The most recent finished deployment for this site — a proxy for "the
     * release `current` is actually pointing at" for UI purposes (the app
     * doesn't track the real symlink target; that's server-side truth only).
     */
    public function currentDeploymentId(): ?int
    {
        return $this->deployments()
            ->where('status', DeploymentStatus::Finished)
            ->latest('id')
            ->value('id');
    }

    /**
     * The release folders most likely to still exist on disk — the newest
     * `releases_to_keep` releases actually created by a deploy (rollbacks reuse
     * an existing folder rather than creating a new one, so they don't count).
     * Used to decide whether to offer a "roll back to this" action in the UI;
     * the authoritative check happens on the server when a rollback runs.
     *
     * @return array<int, string>
     */
    public function availableRollbackReleaseFolders(): array
    {
        return $this->deployments()
            ->where('status', DeploymentStatus::Finished)
            ->where('triggered_by', '!=', 'rollback')
            ->latest('id')
            ->limit((int) config('server.releases_to_keep'))
            ->pluck('ulid')
            ->all();
    }
}
