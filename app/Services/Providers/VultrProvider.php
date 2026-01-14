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

class VultrProvider implements ProviderContract
{
    private string $apiToken = '';

    private PendingRequest $http;

    public function __construct()
    {
        $this->http = Http::baseUrl('https://api.vultr.com/v2')
            ->timeout(30)
            ->retry(3, 100);
    }

    public function setCredentials(array $credentials): void
    {
        $this->apiToken = $credentials['api_token'] ?? '';
        $this->http = Http::baseUrl('https://api.vultr.com/v2')
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
                'vultr',
                $response->status()
            );
        }

        return collect($response->json('regions'))
            ->map(fn ($r) => new ProviderRegion(
                slug: $r['id'],
                name: $r['city'].', '.$r['country'],
            ))
            ->values();
    }

    public function getSizes(): Collection
    {
        $response = $this->http->get('plans');

        if (! $response->successful()) {
            throw new ProviderApiException(
                'Failed to fetch plans: '.$response->body(),
                'vultr',
                $response->status()
            );
        }

        return collect($response->json('plans'))
            ->filter(fn ($p) => $p['type'] === 'vc2') // Regular cloud compute only
            ->map(fn ($p) => new ProviderSize(
                slug: $p['id'],
                vcpus: $p['vcpu_count'],
                memory: $p['ram'],
                disk: $p['disk'],
                priceMonthly: $p['monthly_cost'],
            ))
            ->sortBy('priceMonthly')
            ->values();
    }

    public function createServer(
        string $name,
        string $size,
        string $region,
        string $sshKeyId,
    ): ProviderServerResult {
        // Ubuntu 22.04 LTS image ID for Vultr
        $response = $this->http->post('instances', [
            'label' => $name,
            'hostname' => $name,
            'plan' => $size,
            'region' => $region,
            'os_id' => 1743, // Ubuntu 22.04 LTS x64
            'sshkey_id' => [$sshKeyId],
            'tags' => ['serverforge'],
        ]);

        if (! $response->successful()) {
            throw new ProviderApiException(
                'Failed to create instance: '.$response->body(),
                'vultr',
                $response->status()
            );
        }

        $instance = $response->json('instance');

        return new ProviderServerResult(
            id: $instance['id'],
            name: $instance['label'],
            status: $instance['status'] ?? 'pending',
        );
    }

    public function getServerStatus(string $serverId): ProviderServerStatus
    {
        $response = $this->http->get("instances/{$serverId}");

        if (! $response->successful()) {
            throw new ProviderApiException(
                'Failed to get instance status: '.$response->body(),
                'vultr',
                $response->status()
            );
        }

        $instance = $response->json('instance');

        return new ProviderServerStatus(
            id: $instance['id'],
            status: $instance['status'],
            isActive: $instance['status'] === 'active' && $instance['power_status'] === 'running',
            ipAddress: $instance['main_ip'],
            privateIpAddress: $instance['internal_ip'] ?? null,
        );
    }

    public function deleteServer(string $serverId): void
    {
        $response = $this->http->delete("instances/{$serverId}");

        if (! $response->successful() && $response->status() !== 404) {
            throw new ProviderApiException(
                'Failed to delete instance: '.$response->body(),
                'vultr',
                $response->status()
            );
        }
    }

    public function createSshKey(string $name, string $publicKey): string
    {
        $response = $this->http->post('ssh-keys', [
            'name' => $name,
            'ssh_key' => $publicKey,
        ]);

        if (! $response->successful()) {
            throw new ProviderApiException(
                'Failed to create SSH key: '.$response->body(),
                'vultr',
                $response->status()
            );
        }

        return $response->json('ssh_key.id');
    }

    public function deleteSshKey(string $keyId): void
    {
        $response = $this->http->delete("ssh-keys/{$keyId}");

        if (! $response->successful() && $response->status() !== 404) {
            throw new ProviderApiException(
                'Failed to delete SSH key: '.$response->body(),
                'vultr',
                $response->status()
            );
        }
    }
}
