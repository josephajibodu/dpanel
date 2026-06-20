<?php

namespace App\Models;

use App\Enums\ConnectionStatus;
use App\Enums\Provider;
use App\Enums\ProvisioningStep;
use App\Enums\ServerStatus;
use App\Enums\ServerType;
use App\Enums\ServiceStatus;
use App\Enums\ServiceType;
use App\Events\ServerStatusChanged;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Server extends Model
{
    /** @use HasFactory<\Database\Factories\ServerFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'team_id',
        'user_id',
        'provider_account_id',
        'provider',
        'provider_server_id',
        'cloud_provider_url',
        'name',
        'type',
        'size',
        'region',
        'provider_region_id',
        'provider_size_id',
        'ip_address',
        'private_ip_address',
        'status',
        'provisioning_step',
        'connection_status',
        'php_version',
        'database_type',
        'ubuntu_version',
        'timezone',
        'notes',
        'archived',
        'ssh_port',
        'sudo_password',
        'database_password',
        'local_public_key',
        'provisioned_at',
        'last_ssh_connection_at',
        'meta',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => Provider::class,
            'type' => ServerType::class,
            'status' => ServerStatus::class,
            'provisioning_step' => ProvisioningStep::class,
            'connection_status' => ConnectionStatus::class,
            'archived' => 'boolean',
            'sudo_password' => 'encrypted',
            'database_password' => 'encrypted',
            'provisioned_at' => 'datetime',
            'last_ssh_connection_at' => 'datetime',
            'meta' => 'array',
            'ssh_port' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (Server $server) {
            // Track the previous status before update
            if ($server->isDirty('status')) {
                $originalStatus = $server->getOriginal('status');

                if ($originalStatus instanceof ServerStatus) {
                    $server->previousStatus = $originalStatus;
                } elseif (is_string($originalStatus)) {
                    $server->previousStatus = ServerStatus::tryFrom($originalStatus);
                }
            }

            if ($server->isDirty('provisioning_step')) {
                $originalProvisioningStep = $server->getOriginal('provisioning_step');

                if ($originalProvisioningStep instanceof ProvisioningStep) {
                    $server->previousProvisioningStep = $originalProvisioningStep;
                } elseif (is_numeric($originalProvisioningStep)) {
                    $server->previousProvisioningStep = ProvisioningStep::tryFrom((int) $originalProvisioningStep);
                }
            }
        });

        static::updated(function (Server $server) {
            // Dispatch event if status or provisioning step changed
            $statusChanged = $server->wasChanged('status');
            $provisioningStepChanged = $server->wasChanged('provisioning_step');

            if ($statusChanged || $provisioningStepChanged) {
                event(new ServerStatusChanged(
                    server: $server,
                    previousStatus: $server->previousStatus ?? $server->status,
                    previousProvisioningStep: $server->previousProvisioningStep,
                ));
            }
        });
    }

    public ?ServerStatus $previousStatus = null;

    public ?ProvisioningStep $previousProvisioningStep = null;

    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function providerAccount(): BelongsTo
    {
        return $this->belongsTo(ProviderAccount::class);
    }

    public function providerRegion(): BelongsTo
    {
        return $this->belongsTo(ProviderRegion::class);
    }

    public function providerSize(): BelongsTo
    {
        return $this->belongsTo(ProviderSize::class);
    }

    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    public function sshKeys(): BelongsToMany
    {
        return $this->belongsToMany(SshKey::class, 'server_ssh_key')
            ->using(ServerSshKey::class)
            ->withPivot(['status', 'synced_at'])
            ->withTimestamps();
    }

    public function credentials(): HasMany
    {
        return $this->hasMany(ServerCredential::class);
    }

    public function actions(): HasMany
    {
        return $this->hasMany(ServerAction::class);
    }

    public function databases(): HasMany
    {
        return $this->hasMany(ServerDatabase::class, 'server_id');
    }

    /**
     * Alias for scope binding (route parameter server_database).
     */
    public function serverDatabases(): HasMany
    {
        return $this->databases();
    }

    public function databaseUsers(): HasMany
    {
        return $this->hasMany(DatabaseUser::class);
    }

    public function firewallRules(): HasMany
    {
        return $this->hasMany(FirewallRule::class);
    }

    public function cronJobs(): HasMany
    {
        return $this->hasMany(CronJob::class);
    }

    public function workers(): HasMany
    {
        return $this->hasMany(Worker::class);
    }

    /**
     * Workers not tied to a specific site (daemons).
     */
    public function daemons(): HasMany
    {
        return $this->hasMany(Worker::class)->whereNull('site_id');
    }

    public function metrics(): HasMany
    {
        return $this->hasMany(Metric::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(ServerLog::class);
    }

    /**
     * Installed software services (PHP, Nginx, Redis, etc.) on this server.
     */
    public function installedServices(): HasMany
    {
        return $this->hasMany(Service::class, 'server_id');
    }

    /**
     * Resolve the concrete ServiceType for this server's configured database engine.
     */
    public function databaseServiceType(): ServiceType
    {
        return match ($this->database_type) {
            'postgresql' => ServiceType::Postgresql,
            default => ServiceType::Mysql,
        };
    }

    /**
     * Get the default Service for the given type (is_default = true), or first usable one.
     */
    public function defaultService(ServiceType $type): ?Service
    {
        $service = $this->installedServices()
            ->where('type', $type)
            ->where('is_default', true)
            ->first();

        if ($service !== null) {
            return $service;
        }

        $service = $this->installedServices()
            ->where('type', $type)
            ->whereIn('status', [ServiceStatus::Active, ServiceStatus::Inactive])
            ->first();

        if ($service !== null) {
            $service->update(['is_default' => true]);

            return $service;
        }

        return null;
    }

    /**
     * Find a Service by type and optional version. Does not create. Returns default when version is empty.
     */
    public function service(ServiceType $type, ?string $version = null): ?Service
    {
        if ($version === null || $version === '' || $version === '0') {
            return $this->defaultService($type);
        }

        return $this->installedServices()
            ->where('type', $type)
            ->where('version', $version)
            ->first();
    }

    /**
     * Create a Service record (no install). Only for types this server type includes.
     *
     * Database service types (Mysql, Postgresql) are validated against the generic 'database'
     * capability key in defaultServices(), since the engine is chosen per server instance.
     */
    public function createService(ServiceType $type, ?string $version = null, bool $isDefault = false): Service
    {
        $defaults = $this->type?->defaultServices() ?? [];

        $capabilityKey = match ($type) {
            ServiceType::Mysql, ServiceType::Postgresql => 'database',
            default => $type->value,
        };

        if (! ($defaults[$capabilityKey] ?? false)) {
            throw new \InvalidArgumentException("Server type [{$this->type?->value}] does not include service [{$type->value}].");
        }

        $exists = $this->installedServices()
            ->where('type', $type)
            ->when($version !== null && $version !== '', fn ($q) => $q->where('version', $version))
            ->exists();

        if ($exists) {
            throw new \InvalidArgumentException("Service [{$type->value}]".($version ? " version [{$version}]" : '').' already exists on this server.');
        }

        if ($isDefault) {
            $this->installedServices()->where('type', $type)->update(['is_default' => false]);
        }

        return $this->installedServices()->create([
            'type' => $type,
            'version' => $version,
            'status' => ServiceStatus::Pending,
            'is_default' => $isDefault,
        ]);
    }

    public function php(?string $version = null): ?Service
    {
        return $this->service(ServiceType::Php, $version);
    }

    public function nginx(?string $version = null): ?Service
    {
        return $this->service(ServiceType::Nginx, $version);
    }

    public function database(?string $version = null): ?Service
    {
        return $this->service($this->databaseServiceType(), $version);
    }

    public function redis(?string $version = null): ?Service
    {
        return $this->service(ServiceType::Redis, $version);
    }

    public function supervisor(?string $version = null): ?Service
    {
        return $this->service(ServiceType::Supervisor, $version);
    }

    public function credential(string $type = 'private_key'): ?ServerCredential
    {
        return $this->credentials()->where('type', $type)->first();
    }

    /**
     * Check if the server is ready (active and connected).
     */
    public function isReady(): bool
    {
        return $this->status === ServerStatus::Active
            && $this->connection_status === ConnectionStatus::Successful;
    }

    /**
     * Check if the server supports sites based on its type.
     */
    public function supportsSites(): bool
    {
        return $this->type?->supportsSites() ?? true;
    }

    /**
     * Get the service types and whether they are enabled for this server type.
     *
     * @return array<string, bool>
     */
    public function defaultServiceTypes(): array
    {
        return $this->meta['services'] ?? $this->type?->defaultServices() ?? [];
    }

    /**
     * PHP version for backward compatibility (denormalized from default PHP Service).
     */
    public function getDefaultPhpVersion(): ?string
    {
        $php = $this->php();

        if ($php !== null) {
            return $php->installed_version ?? $php->version ?? null;
        }

        return $this->getAttribute('php_version');
    }
}
