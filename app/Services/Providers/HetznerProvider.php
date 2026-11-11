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
        'general_purpose' => 'General purpose',
    ];

    /**
     * Labels for the kinds of disk Hetzner attaches to a server type.
     */
    private const array DISK_TYPE_LABELS = [
        'local' => 'NVMe SSD',
        'network' => 'Network storage',
    ];

    private const int BYTES_PER_TB = 1024 ** 4;

    /**
     * The most items Hetzner returns per page. It sends 25 by default, which
     * is fewer than the server types it sells.
     */
    private const int MAX_PER_PAGE = 50;

    /**
     * Hetzner's price list, fetched once per instance: it holds the account's
     * currency and the per-location prices of a public IPv4 address and of
     * traffic beyond the included amount.
     *
     * @var array<string, mixed>|null
     */
    private ?array $pricing = null;

    /**
     * Each location carries what Hetzner bills there on top of the server
     * itself: the public IPv4 address, and traffic beyond the included amount.
     */
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

        $pricing = $this->pricing();

        $ipv4Prices = collect(collect($pricing['primary_ips'] ?? [])->firstWhere('type', 'ipv4')['prices'] ?? [])
            ->keyBy('location');

        // Hetzner lists the traffic price on every server type, but it is the
        // same for all types in a location.
        $trafficPrices = collect($pricing['server_types'] ?? [])
            ->flatMap(fn ($type) => $type['prices'] ?? [])
            ->keyBy('location');

        return collect($response->json('locations'))
            ->map(function ($l) use ($ipv4Prices, $trafficPrices) {
                $ipv4Price = $ipv4Prices[$l['name']]['price_monthly']['net'] ?? null;
                $trafficPrice = $trafficPrices[$l['name']]['price_per_tb_traffic']['net'] ?? null;

                return new ProviderRegion(
                    slug: $l['name'],
                    name: $l['description'].' ('.$l['city'].')',
                    extraCharges: $ipv4Price === null ? [] : [
                        ['label' => 'Public IPv4 address', 'price_monthly' => (float) $ipv4Price],
                    ],
                    trafficPricePerTb: $trafficPrice === null ? null : (float) $trafficPrice,
                );
            })
            ->values();
    }

    /**
     * Each size carries the locations Hetzner lists it in, with its price and
     * included traffic in each, before tax, which is how Hetzner's console
     * shows it. Locations where it is out of stock are kept and marked.
     */
    public function getSizes(): Collection
    {
        $response = $this->http->get('server_types', ['per_page' => self::MAX_PER_PAGE]);

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
                $locations = collect($s['locations'] ?? [])
                    ->filter(fn ($l) => ($l['deprecation'] ?? null) === null);

                $regions = $locations->pluck('name');

                $regionOverrides = collect($s['prices'] ?? [])
                    ->filter(fn ($p) => $regions->contains($p['location']))
                    ->mapWithKeys(fn ($p) => [$p['location'] => [
                        'price_monthly' => (float) $p['price_monthly']['net'],
                        'price_hourly' => isset($p['price_hourly']) ? (float) $p['price_hourly']['net'] : null,
                        'transfer' => isset($p['included_traffic']) ? round($p['included_traffic'] / self::BYTES_PER_TB, 2) : null,
                    ]]);

                $cheapest = $regionOverrides->sortBy('price_monthly')->first();

                return new ProviderSize(
                    slug: $s['name'],
                    vcpus: $s['cores'],
                    memory: (int) round($s['memory'] * 1024), // Convert GB to MB
                    disk: $s['disk'],
                    priceMonthly: $cheapest['price_monthly'] ?? 0.0,
                    label: $s['description'] ?? null,
                    category: self::CATEGORY_LABELS[$s['category'] ?? ''] ?? null,
                    regions: $regions->values()->all(),
                    regionOverrides: $regionOverrides->all(),
                    currency: $currency,
                    priceHourly: $cheapest['price_hourly'] ?? null,
                    transfer: $cheapest['transfer'] ?? null,
                    architecture: $s['architecture'] ?? null,
                    cpuType: $s['cpu_type'] ?? null,
                    diskType: self::DISK_TYPE_LABELS[$s['storage_type'] ?? ''] ?? null,
                    unavailableRegions: $locations->reject(fn ($l) => $l['available'] ?? true)->pluck('name')->values()->all(),
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
