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
        $this->pricing = null;
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

    /**
     * Labels for Hetzner's server type categories, as its console names them.
     */
    private const array CATEGORY_LABELS = [
        'cost_optimized' => 'Cost-optimized',
        'regular_purpose' => 'Regular performance',
        'general_purpose' => 'General purpose (dedicated vCPU)',
    ];

    /**
     * Hetzner's price list, fetched once per instance: it holds the account's
     * currency and the per-location price of a public IPv4 address.
     *
     * @var array<string, mixed>|null
     */
    private ?array $pricing = null;

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

        $ipv4Prices = collect($this->pricing()['primary_ips'] ?? [])
            ->firstWhere('type', 'ipv4')['prices'] ?? [];

        return collect($response->json('locations'))
            ->map(function ($l) use ($ipv4Prices) {
                $ipv4Price = collect($ipv4Prices)->firstWhere('location', $l['name'])['price_monthly']['net'] ?? null;

                return new ProviderRegion(
                    slug: $l['name'],
                    name: $l['description'].' ('.$l['city'].')',
                    ipv4PriceMonthly: $ipv4Price === null ? null : (float) $ipv4Price,
                );
            })
            ->values();
    }

    /**
     * Each size carries the locations it can be created in today and its
     * price in each, before tax, which is how Hetzner's console shows it.
     */
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

        $currency = $this->pricing()['currency'] ?? 'EUR';

        return collect($response->json('server_types'))
            ->filter(fn ($s) => ($s['deprecation'] ?? null) === null && ! ($s['deprecated'] ?? false))
            ->map(function ($s) use ($currency) {
                $regions = collect($s['locations'] ?? [])
                    ->filter(fn ($l) => ($l['available'] ?? true) && ($l['deprecation'] ?? null) === null)
                    ->pluck('name');

                $regionPrices = collect($s['prices'] ?? [])
                    ->filter(fn ($p) => $regions->contains($p['location']))
                    ->mapWithKeys(fn ($p) => [$p['location'] => (float) $p['price_monthly']['net']]);

                $category = self::CATEGORY_LABELS[$s['category'] ?? ''] ?? null;
                if ($category !== null && ($s['architecture'] ?? null) === 'arm') {
                    $category .= ' (Arm)';
                }

                return new ProviderSize(
                    slug: $s['name'],
                    vcpus: $s['cores'],
                    memory: (int) round($s['memory'] * 1024), // Convert GB to MB
                    disk: $s['disk'],
                    priceMonthly: (float) ($regionPrices->min() ?? 0),
                    label: $s['description'] ?? null,
                    category: $category,
                    regions: $regions->values()->all(),
                    regionPrices: $regionPrices->all(),
                    currency: $currency,
                );
            })
            ->filter(fn (ProviderSize $size) => $size->regions !== [])
            ->sortBy('priceMonthly')
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function pricing(): array
    {
        if ($this->pricing !== null) {
            return $this->pricing;
        }

        $response = $this->http->get('pricing');

        if (! $response->successful()) {
            throw new ProviderApiException(
                'Failed to fetch pricing: '.$response->body(),
                'hetzner',
                $response->status()
            );
        }

        return $this->pricing = $response->json('pricing') ?? [];
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
            'image' => 'ubuntu-24.04',
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

    public function createSshKey(string $name, string $publicKey, string $region): string
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
