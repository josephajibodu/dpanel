<?php

namespace App\Data;

readonly class ProviderRegion
{
    /**
     * @param  list<array{label: string, price_monthly: float}>  $extraCharges  What the provider bills each month on top of the size
     *                                                                          for a server in this region, e.g. its public IPv4 address.
     * @param  float|null  $trafficPricePerTb  What the provider charges per TB of traffic beyond the amount included with the size.
     */
    public function __construct(
        public string $slug,
        public string $name,
        public array $extraCharges = [],
        public ?float $trafficPricePerTb = null,
    ) {}

    /**
     * @return array{
     *     slug: string,
     *     name: string,
     *     extra_charges: list<array{label: string, price_monthly: float}>,
     *     traffic_price_per_tb: float|null
     * }
     */
    public function toArray(): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'extra_charges' => $this->extraCharges,
            'traffic_price_per_tb' => $this->trafficPricePerTb,
        ];
    }
}
