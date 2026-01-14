<?php

namespace App\Services\Providers;

use App\Contracts\ProviderContract;
use App\Data\ProviderRegion;
use App\Data\ProviderServerResult;
use App\Data\ProviderServerStatus;
use App\Data\ProviderSize;
use App\Exceptions\ProviderApiException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

class HetznerProvider implements ProviderContract
{
    private string $apiToken = '';

    private PendingRequest $http;

    public function __construct()
    {
        $this->http = Http::baseUrl('https://api.hetzner.cloud/v1')
            ->timeout(30)
            ->retry(3, 100);
    }

    public function setCredentials(array $credentials): void
    {
        $this->apiToken = $credentials['api_token'] ?? '';
        $this->http = Http::baseUrl('https://api.hetzner.cloud/v1')
            ->timeout(30)
            ->retry(3, 100)
            ->withToken($this->apiToken);
    }

    public function validateCredentials(): bool
    {
        try {
            $response = $this->http->get('servers', ['per_page' => 1]);

            return $response->successful();
        } catch (\Exception) {
            return false;
        }
    }

    public function getRegions(): Collection
    {
        $response = $this->http->get('locations');

        if (! $response->successful()) {
            throw new ProviderApiException(
                'Failed to fetch locations: '.$response->body(),
                'hetzner',
                $response->status()
            );
        }

        return collect($response->json('locations'))
            ->map(fn ($l) => new ProviderRegion(
                slug: $l['name'],
                name: $l['description'].' ('.$l['city'].')',
            ))
            ->values();
    }

    public function getSizes(): Collection
    {
        $response = $this->http->get('server_types');

        if (! $response->successful()) {
            throw new ProviderApiException(
                'Failed to fetch server types: '.$response->body(),
                'hetzner',
                $response->status()
            );
        }

        return collect($response->json('server_types'))
            ->filter(fn ($s) => ! $s['deprecated'])
            ->map(function ($s) {
                $priceMonthly = collect($s['prices'] ?? [])
                    ->firstWhere('location', 'fsn1')['price_monthly']['gross'] ?? 0;

                return new ProviderSize(
                    slug: $s['name'],
                    vcpus: $s['cores'],
                    memory: $s['memory'] * 1024, // Convert GB to MB
                    disk: $s['disk'],
                    priceMonthly: (float) $priceMonthly,
                );
            })
            ->sortBy('priceMonthly')
            ->values();
    }

    public function createServer(
        string $name,
        string $size,
        string $region,
        string $sshKeyId,
    ): ProviderServerResult {
        $response = $this->http->post('servers', [
            'name' => $name,
            'server_type' => $size,
            'location' => $region,
            'image' => 'ubuntu-22.04',
            'ssh_keys' => [$sshKeyId],
            'start_after_create' => true,
            'labels' => ['app' => 'serverforge'],
        ]);

        if (! $response->successful()) {
            throw new ProviderApiException(
                'Failed to create server: '.$response->body(),
                'hetzner',
                $response->status()
            );
        }

        $server = $response->json('server');

        return new ProviderServerResult(
            id: (string) $server['id'],
            name: $server['name'],
            status: $server['status'],
        );
    }

    public function getServerStatus(string $serverId): ProviderServerStatus
    {
        $response = $this->http->get("servers/{$serverId}");

        if (! $response->successful()) {
            throw new ProviderApiException(
                'Failed to get server status: '.$response->body(),
                'hetzner',
                $response->status()
            );
        }

        $server = $response->json('server');

        return new ProviderServerStatus(
            id: (string) $server['id'],
            status: $server['status'],
            isActive: $server['status'] === 'running',
            ipAddress: $server['public_net']['ipv4']['ip'] ?? null,
            privateIpAddress: null,
        );
    }

    public function deleteServer(string $serverId): void
    {
        $response = $this->http->delete("servers/{$serverId}");

        if (! $response->successful() && $response->status() !== 404) {
            throw new ProviderApiException(
                'Failed to delete server: '.$response->body(),
                'hetzner',
                $response->status()
            );
        }
    }

    public function createSshKey(string $name, string $publicKey): string
    {
        $response = $this->http->post('ssh_keys', [
            'name' => $name,
            'public_key' => $publicKey,
            'labels' => ['app' => 'serverforge'],
        ]);

        if (! $response->successful()) {
            throw new ProviderApiException(
                'Failed to create SSH key: '.$response->body(),
                'hetzner',
                $response->status()
            );
        }

        return (string) $response->json('ssh_key.id');
    }

    public function deleteSshKey(string $keyId): void
    {
        $response = $this->http->delete("ssh_keys/{$keyId}");

        if (! $response->successful() && $response->status() !== 404) {
            throw new ProviderApiException(
                'Failed to delete SSH key: '.$response->body(),
                'hetzner',
                $response->status()
            );
        }
    }
}
