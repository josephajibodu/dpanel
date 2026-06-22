<?php

namespace App\Models;

use App\Enums\ProjectType;
use App\Enums\RepositoryProvider;
use App\Enums\SiteProvisioningStep;
use App\Enums\SiteStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Site extends Model
{
    /** @use HasFactory<\Database\Factories\SiteFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'server_id',
        'source_control_account_id',
        'domain',
        'root_path',
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
        'provisioning_step',
        'deploy_key_id',
        'webhook_secret',
        'auto_deploy',
        'deployment_started_at',
        'deployment_finished_at',
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
        ];
    }

    /**
     * Get the root path for this site on the server.
     */
    public function rootPath(): string
    {
        $serverUser = config('server.user');
        $segment = $this->root_path ?? $this->domain;

        return "/home/{$serverUser}/{$segment}";
    }

    /**
     * Get the full web root path including the directory.
     */
    public function webRoot(): string
    {
        $directory = $this->directory ?: '/';

        return rtrim($this->rootPath(), '/').'/'.ltrim($directory, '/');
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
}
