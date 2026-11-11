<?php

namespace App\Actions\Servers;

use App\Data\ProviderRegion as ProviderRegionData;
use App\Data\ProviderSize as ProviderSizeData;
use App\Models\ProviderAccount;
use App\Models\ProviderRegion;
use App\Models\ProviderSize;
use App\Services\Providers\ProviderManager;
use Illuminate\Support\Facades\Cache;

/**
 * Fetch the regions and server sizes a provider account can create servers in.
 *
 * Asking the provider's API is slow, so the answer is cached for an hour. Each
 * fresh answer is also saved to the provider_regions / provider_sizes tables,
 * which CreateServerAction reads when it records the new server.
 */
class FetchProviderCatalogAction
{
    private const CACHE_TTL_SECONDS = 3600;

    public function __construct(
        private ProviderManager $providerManager,
    ) {}

    /**
     * @return array{
     *     regions: list<array<string, mixed>>,
     *     sizes: list<array<string, mixed>>
     * }
     */
    public function execute(ProviderAccount $account): array
    {
        return Cache::remember(
            "provider-accounts:{$account->id}:catalog:v3",
            self::CACHE_TTL_SECONDS,
            fn (): array => $this->fetchFromProvider($account),
        );
    }

    /**
     * @return array{
     *     regions: list<array<string, mixed>>,
     *     sizes: list<array<string, mixed>>
     * }
     */
    private function fetchFromProvider(ProviderAccount $account): array
    {
        $provider = $this->providerManager->forAccount($account);

        $regions = $provider->getRegions();
        $sizes = $provider->getSizes()->sortBy('priceMonthly')->values();

        $regions->each(fn (ProviderRegionData $region) => ProviderRegion::updateOrCreate(
            ['provider' => $account->provider, 'code' => $region->slug],
            ['name' => $region->name],
        ));

        $sizes->each(fn (ProviderSizeData $size) => ProviderSize::updateOrCreate(
            ['provider' => $account->provider, 'code' => $size->slug],
            [
                'name' => $size->description(),
                'memory' => $size->memory >= 1024 ? ($size->memory / 1024).' GB' : $size->memory.' MB',
                'disk' => $size->disk.' GB',
                'cpus' => $size->vcpus,
                'price_monthly' => $size->priceMonthly,
            ],
        ));

        return [
            'regions' => $regions->map->toArray()->values()->all(),
            'sizes' => $sizes->map->toArray()->all(),
        ];
    }
}
