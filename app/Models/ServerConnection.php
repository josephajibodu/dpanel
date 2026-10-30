<?php

namespace App\Models;

use App\Enums\ServerConnectionStatus;
use Database\Factories\ServerConnectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lets the client server reach the host server's database and Redis. The
 * host's firewall only opens those ports to client_address.
 */
class ServerConnection extends Model
{
    /** @use HasFactory<ServerConnectionFactory> */
    use HasFactory;

    protected $fillable = [
        'server_id',
        'client_server_id',
        'host_address',
        'client_address',
        'status',
        'error_message',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ServerConnectionStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Server, $this>
     */
    public function host(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'server_id');
    }

    /**
     * @return BelongsTo<Server, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'client_server_id');
    }

    /**
     * The addresses the two servers reach each other on: their private IPs
     * when both are in the same provider region (traffic stays off the public
     * internet), their public IPs otherwise.
     *
     * @return array{host: string, client: string}
     */
    public static function addressesBetween(Server $host, Server $client): array
    {
        if (self::shareAPrivateNetwork($host, $client)) {
            return ['host' => $host->private_ip_address, 'client' => $client->private_ip_address];
        }

        return ['host' => (string) $host->ip_address, 'client' => (string) $client->ip_address];
    }

    public static function shareAPrivateNetwork(Server $host, Server $client): bool
    {
        return $host->private_ip_address !== null
            && $client->private_ip_address !== null
            && $host->provider === $client->provider
            && $host->region !== null
            && $host->region === $client->region;
    }

    public function usesPrivateNetwork(): bool
    {
        return $this->host_address === $this->host?->private_ip_address;
    }

    /**
     * Marker identifying this connection's firewall and pg_hba.conf entries.
     */
    public function marker(): string
    {
        return "flitops-connection-{$this->id}";
    }
}
