<?php

namespace App\Models;

use App\Enums\ConnectionStatus;
use App\Enums\Provider;
use App\Enums\ProvisioningStep;
use App\Enums\ServerStatus;
use App\Enums\ServerType;
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
        'user_id',
        'provider_account_id',
        'provider',
        'provider_server_id',
        'cloud_provider_url',
        'name',
        'type',
        'size',
        'region',
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
                $server->previousStatus = $server->getOriginal('status');
            }
        });

        static::updated(function (Server $server) {
            // Dispatch event if status changed
            if (isset($server->previousStatus) && $server->previousStatus !== $server->status) {
                event(new ServerStatusChanged($server, $server->previousStatus));
            }
        });
    }

    public ?ServerStatus $previousStatus = null;

    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function providerAccount(): BelongsTo
    {
        return $this->belongsTo(ProviderAccount::class);
    }

    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    public function sshKeys(): BelongsToMany
    {
        return $this->belongsToMany(SshKey::class, 'server_ssh_key')
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
     * Get the installed services for this server.
     *
     * @return array<string, bool>
     */
    public function services(): array
    {
        return $this->meta['services'] ?? $this->type?->defaultServices() ?? [];
    }
}
