<?php

namespace App\Models;

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
        'domain',
        'aliases',
        'directory',
        'repository',
        'repository_provider',
        'branch',
        'project_type',
        'php_version',
        'status',
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
            'aliases' => 'array',
            'auto_deploy' => 'boolean',
            'deployment_started_at' => 'datetime',
            'deployment_finished_at' => 'datetime',
        ];
    }

    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
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
}
