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

class DigitalOceanProvider implements ProviderContract
{
    private string $apiToken = '';

    private PendingRequest $http;

    public function __construct()
    {
        $this->http = Http::baseUrl('https://api.digitalocean.com/v2')
            ->timeout(30)
            ->retry(3, 100);
    }

    public function setCredentials(array $credentials): void
    {
        $this->apiToken = $credentials['api_token'] ?? '';
        $this->http = Http::baseUrl('https://api.digitalocean.com/v2')
            ->timeout(30)
            ->retry(3, 100)
            ->withToken($this->apiToken);
    }

    public function validateCredentials(): bool
    {
        try {
            $response = $this->http->get('account');

            return $response->successful();
        } catch (\Exception) {
            return false;
        }
    }

    public function getRegions(): Collection
    {
        $response = $this->http->get('regions');

        if (! $response->successful()) {
            throw new ProviderApiException(
                'Failed to fetch regions: '.$response->body(),
                'digitalocean',
                $response->status()
            );
        }

        return collect($response->json('regions'))
            ->filter(fn ($r) => $r['available'])
            ->map(fn ($r) => new ProviderRegion(
                slug: $r['slug'],
                name: $r['name'],
            ))
            ->values();
    }

    public function getSizes(): Collection
    {
        $response = $this->http->get('sizes');

        if (! $response->successful()) {
            throw new ProviderApiException(
                'Failed to fetch sizes: '.$response->body(),
                'digitalocean',
                $response->status()
            );
        }

        return collect($response->json('sizes'))
            ->filter(fn ($s) => $s['available'])
            ->map(fn ($s) => new ProviderSize(
                slug: $s['slug'],
                vcpus: $s['vcpus'],
                memory: $s['memory'],
                disk: $s['disk'],
                priceMonthly: $s['price_monthly'],
            ))
            ->sortBy('price_monthly')
            ->values();
    }

    public function createServer(
        string $name,
        string $size,
        string $region,
        string $sshKeyId,
    ): ProviderServerResult {
        $response = $this->http->post('droplets', [
            'name' => $name,
            'region' => $region,
            'size' => $size,
            'image' => 'ubuntu-22-04-x64',
            'ssh_keys' => [$sshKeyId],
            'backups' => false,
            'ipv6' => false,
            'monitoring' => true,
            'tags' => ['serverforge'],
        ]);

        if (! $response->successful()) {
            throw new ProviderApiException(
                'Failed to create droplet: '.$response->body(),
                'digitalocean',
                $response->status()
            );
        }

        $droplet = $response->json('droplet');

        return new ProviderServerResult(
            id: (string) $droplet['id'],
            name: $droplet['name'],
            status: $droplet['status'],
        );
    }

    public function getServerStatus(string $serverId): ProviderServerStatus
    {
        $response = $this->http->get("droplets/{$serverId}");

        if (! $response->successful()) {
            throw new ProviderApiException(
                'Failed to get droplet status: '.$response->body(),
                'digitalocean',
                $response->status()
            );
        }

        $droplet = $response->json('droplet');

        $publicIp = collect($droplet['networks']['v4'] ?? [])
            ->firstWhere('type', 'public')['ip_address'] ?? null;

        $privateIp = collect($droplet['networks']['v4'] ?? [])
            ->firstWhere('type', 'private')['ip_address'] ?? null;

        return new ProviderServerStatus(
            id: (string) $droplet['id'],
            status: $droplet['status'],
            isActive: $droplet['status'] === 'active',
            ipAddress: $publicIp,
            privateIpAddress: $privateIp,
        );
    }

    public function deleteServer(string $serverId): void
    {
        $response = $this->http->delete("droplets/{$serverId}");

        if (! $response->successful() && $response->status() !== 404) {
            throw new ProviderApiException(
                'Failed to delete droplet: '.$response->body(),
                'digitalocean',
                $response->status()
            );
        }
    }

    public function createSshKey(string $name, string $publicKey): string
    {
        $response = $this->http->post('account/keys', [
            'name' => $name,
            'public_key' => $publicKey,
        ]);

        if (! $response->successful()) {
            throw new ProviderApiException(
                'Failed to create SSH key: '.$response->body(),
                'digitalocean',
                $response->status()
            );
        }

        return (string) $response->json('ssh_key.id');
    }

    public function deleteSshKey(string $keyId): void
    {
        $response = $this->http->delete("account/keys/{$keyId}");

        if (! $response->successful() && $response->status() !== 404) {
            throw new ProviderApiException(
                'Failed to delete SSH key: '.$response->body(),
                'digitalocean',
                $response->status()
            );
        }
    }
}
