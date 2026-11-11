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

    /**
     * The plan families offered, as Vultr's console names them, keyed by the
     * start of the plan ID. GPU, bare-metal and block-storage-booted plans
     * are left out: they need images or disks FlitOps doesn't set up.
     *
     * @var array<string, array{category: string, cpu_type: string, disk_type: string}>
     */
    private const array PLAN_FAMILIES = [
        'vc2-' => ['category' => 'Regular performance', 'cpu_type' => 'shared', 'disk_type' => 'SSD'],
        'vhp-' => ['category' => 'High performance', 'cpu_type' => 'shared', 'disk_type' => 'NVMe SSD'],
        'vhf-' => ['category' => 'High frequency', 'cpu_type' => 'shared', 'disk_type' => 'NVMe SSD'],
        'voc-g-' => ['category' => 'General purpose', 'cpu_type' => 'dedicated', 'disk_type' => 'NVMe SSD'],
        'voc-c-' => ['category' => 'CPU optimized', 'cpu_type' => 'dedicated', 'disk_type' => 'NVMe SSD'],
        'voc-m-' => ['category' => 'Memory optimized', 'cpu_type' => 'dedicated', 'disk_type' => 'NVMe SSD'],
        'voc-s-' => ['category' => 'Storage optimized', 'cpu_type' => 'dedicated', 'disk_type' => 'NVMe SSD'],
    ];

    /**
     * The most plans Vultr returns per page. It sends 100 by default, which
     * is fewer than the plans it sells.
     */
    private const int MAX_PER_PAGE = 500;

    private const int GB_PER_TB = 1024;

    /**
     * Each plan carries the regions it is sold in and the regions where
     * Vultr charges more for it than its usual price.
     */
    public function getSizes(): Collection
    {
        $response = $this->http->get('plans', ['per_page' => self::MAX_PER_PAGE]);

        if (! $response->successful()) {
            throw new ProviderApiException(
                'Failed to fetch plans: '.$response->body(),
                'vultr',
                $response->status()
            );
        }

        return collect($response->json('plans'))
            ->filter(fn ($p) => $this->planFamily($p['id']) !== null && $this->canBeProvisioned($p))
            ->map(function ($p) {
                $family = $this->planFamily($p['id']);

                return new ProviderSize(
                    slug: $p['id'],
                    vcpus: $p['vcpu_count'],
                    memory: $p['ram'],
                    disk: $p['disk'],
                    priceMonthly: (float) $p['monthly_cost'],
                    label: $p['id'],
                    category: $family['category'],
                    regions: $p['locations'],
                    regionOverrides: collect($p['location_cost'] ?? [])
                        ->map(fn ($cost) => [
                            'price_monthly' => (float) $cost['monthly_cost'],
                            'price_hourly' => isset($cost['hourly_cost']) ? (float) $cost['hourly_cost'] : null,
                        ])
                        ->all(),
                    priceHourly: isset($p['hourly_cost']) ? (float) $p['hourly_cost'] : null,
                    transfer: isset($p['bandwidth']) ? round($p['bandwidth'] / self::GB_PER_TB, 2) : null,
                    architecture: 'x86',
                    cpuType: $family['cpu_type'],
                    cpuVendor: $p['cpu_vendor'] ?? null,
                    diskType: $family['disk_type'],
                );
            })
            ->sortBy('priceMonthly')
            ->values();
    }

    /**
     * @return array{category: string, cpu_type: string, disk_type: string}|null
     */
    private function planFamily(string $planId): ?array
    {
        foreach (self::PLAN_FAMILIES as $prefix => $family) {
            if (str_starts_with($planId, $prefix)) {
                return $family;
            }
        }

        return null;
    }

    /**
     * Whether FlitOps can set up a server on this plan. It needs a region to
     * create it in and a public IPv4 address to connect to, which Vultr's
     * IPv6-only plans ("…-v6") lack. The free plan is left out because Vultr
     * only grants it to accounts it has approved.
     *
     * @param  array<string, mixed>  $plan
     */
    private function canBeProvisioned(array $plan): bool
    {
        return ($plan['locations'] ?? []) !== []
            && ! str_ends_with($plan['id'], '-v6')
            && (float) $plan['monthly_cost'] > 0;
    }

    public function createServer(
        string $name,
        string $size,
        string $region,
        string $sshKeyId,
    ): ProviderServerResult {
        // Ubuntu 24.04 LTS image ID for Vultr
        $response = $this->http->post('instances', [
            'label' => $name,
            'hostname' => $name,
            'plan' => $size,
            'region' => $region,
            'os_id' => 2284, // Ubuntu 24.04 LTS x64
            'sshkey_id' => [$sshKeyId],
            'backups' => 'disabled',
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

    public function createSshKey(string $name, string $publicKey, string $region): string
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
