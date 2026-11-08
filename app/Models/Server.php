<?php

namespace App\Models;

use App\Contracts\WebServerContract;
use App\Enums\ConnectionStatus;
use App\Enums\NodeVersion;
use App\Enums\Provider;
use App\Enums\ProvisioningStep;
use App\Enums\ServerConnectionStatus;
use App\Enums\ServerStatus;
use App\Enums\ServerType;
use App\Enums\ServiceStatus;
use App\Enums\ServiceType;
use App\Events\ServerStatusChanged;
use App\Services\WebServer\CaddyWebServer;
use App\Services\WebServer\NginxWebServer;
use Database\Factories\ServerFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Server extends Model
{
    /** @use HasFactory<ServerFactory> */
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
        'error_message',
        'provisioning_step',
        'connection_status',
        'php_version',
        'web_server',
        'node_version',
        'node_status',
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
        'host_key_type',
        'host_key_fingerprint',
        'meta',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'sudo_password',
        'database_password',
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
            'node_version' => NodeVersion::class,
            'node_status' => ServiceStatus::class,
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
            ->withPivot(['site_id', 'system_user', 'status', 'synced_at'])
            ->withTimestamps();
    }

    public function terminalSessions(): HasMany
    {
        return $this->hasMany(TerminalSession::class);
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
     * Line-by-line transcript of the provisioning SSH session.
     */
    public function provisioningLogs(): HasMany
    {
        return $this->hasMany(ServerProvisioningLog::class);
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
     * Create a Service record (no install).
     */
    public function createService(ServiceType $type, ?string $version = null, bool $isDefault = false): Service
    {
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
     * Redact known secret credential values (sudo/database passwords) from a
     * message before it is stored, emailed, or broadcast. SSH command
     * failures can embed these plaintext values verbatim.
     */
    public function redactSecrets(string $message): string
    {
        $secrets = $this->secretValues();

        return $secrets === [] ? $message : str_replace($secrets, '[redacted]', $message);
    }

    /**
     * The plaintext sudo and database passwords for this server.
     *
     * @return array<int, string>
     */
    public function secretValues(): array
    {
        return $this->credentials()
            ->whereIn('type', ['sudo_password', 'database_password', 'redis_password'])
            ->pluck('value')
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Whether a host key has been recorded for this server yet.
     */
    public function hasPinnedHostKey(): bool
    {
        return $this->host_key_fingerprint !== null;
    }

    /**
     * Record the host key this server presented, so later connections can
     * check they are talking to the same machine.
     */
    public function pinHostKey(string $type, string $fingerprint): void
    {
        $this->update([
            'host_key_type' => $type,
            'host_key_fingerprint' => $fingerprint,
        ]);
    }

    /**
     * Forget the recorded host key, for a server that really was rebuilt or
     * reinstalled. The next successful connection records the new key.
     */
    public function forgetHostKey(): void
    {
        $this->update([
            'host_key_type' => null,
            'host_key_fingerprint' => null,
        ]);
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
     * The web server this server runs. Servers without one are treated as
     * Nginx, which is only reached by code that never runs for them.
     */
    public function webServer(): WebServerContract
    {
        return match ($this->web_server) {
            'caddy' => app(CaddyWebServer::class),
            default => app(NginxWebServer::class),
        };
    }

    /**
     * Connections letting other servers reach this server's database and Redis.
     *
     * @return HasMany<ServerConnection, $this>
     */
    public function hostedConnections(): HasMany
    {
        return $this->hasMany(ServerConnection::class, 'server_id');
    }

    /**
     * Connections letting this server reach other servers' databases and Redis.
     *
     * @return HasMany<ServerConnection, $this>
     */
    public function clientConnections(): HasMany
    {
        return $this->hasMany(ServerConnection::class, 'client_server_id');
    }

    /**
     * The services other servers can connect to, by label, with their ports.
     * Redis is only shared from servers without sites: sharing it means
     * setting a password, which would break sites using it with none.
     *
     * @return array<string, int>
     */
    public function sharedServicePorts(): array
    {
        $ports = [];

        if ($this->hasDatabase()) {
            $label = match ($this->database_type) {
                'postgresql' => 'PostgreSQL',
                'mariadb' => 'MariaDB',
                default => 'MySQL',
            };
            $ports[$label] = $this->database_type === 'postgresql' ? 5432 : 3306;
        }

        if ($this->sharesRedis()) {
            $ports['Redis'] = 6379;
        }

        return $ports;
    }

    public function sharesRedis(): bool
    {
        return ! $this->supportsSites() && $this->hasRedis();
    }

    public function acceptsConnections(): bool
    {
        return $this->sharedServicePorts() !== [];
    }

    /**
     * IDs of the servers whose databases this server's sites can use: itself,
     * plus every server it has an active connection to.
     *
     * @return array<int, int>
     */
    public function reachableDatabaseServerIds(): array
    {
        return [
            $this->id,
            ...$this->clientConnections()
                ->where('status', ServerConnectionStatus::Active)
                ->pluck('server_id')
                ->all(),
        ];
    }

    /**
     * Whether other servers may be connecting to this server's database, so
     * database users need an account other servers can sign in with.
     */
    public function hasRemoteDatabaseClients(): bool
    {
        return $this->hostedConnections()->exists();
    }

    /**
     * Sites need both a web server and PHP to run on.
     */
    public function supportsSites(): bool
    {
        return $this->web_server !== null && $this->hasPhp();
    }

    public function hasPhp(): bool
    {
        return $this->php_version !== null;
    }

    /**
     * The services on this server that can be restarted from the dashboard,
     * in the order they're shown. Supervisor is left out: restarting it
     * force-stops every worker, and each worker has its own Restart.
     *
     * @return list<ServiceType>
     */
    public function restartableServices(): array
    {
        return array_values(array_filter([
            match ($this->web_server) {
                'nginx' => ServiceType::Nginx,
                'caddy' => ServiceType::Caddy,
                default => null,
            },
            $this->hasPhp() ? ServiceType::Php : null,
            match ($this->database_type) {
                'mysql', 'mariadb' => ServiceType::Mysql,
                'postgresql' => ServiceType::Postgresql,
                default => null,
            },
            $this->hasRedis() ? ServiceType::Redis : null,
        ]));
    }

    public function hasDatabase(): bool
    {
        return $this->database_type !== null;
    }

    public function hasRedis(): bool
    {
        return $this->relationLoaded('installedServices')
            ? $this->installedServices->contains('type', ServiceType::Redis)
            : $this->installedServices()->where('type', ServiceType::Redis)->exists();
    }

    /**
     * Record the services chosen for this server as Pending, ready for the
     * stack installer to install them. Services already recorded are skipped,
     * so this is safe to call again.
     */
    public function queueSelectedServices(bool $withRedis): void
    {
        if ($this->hasPhp()) {
            $this->queueService(ServiceType::Php, $this->php_version);
        }

        if ($this->web_server !== null) {
            $this->queueService($this->webServer()->serviceType());
        }

        if ($this->hasDatabase()) {
            $this->queueService($this->databaseServiceType());
        }

        if ($withRedis) {
            $this->queueService(ServiceType::Redis);
        }
    }

    private function queueService(ServiceType $type, ?string $version = null): void
    {
        if (! $this->installedServices()->where('type', $type)->exists()) {
            $this->createService($type, $version, true);
        }
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
