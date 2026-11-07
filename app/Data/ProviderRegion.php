<?php

namespace App\Data;

readonly class ProviderRegion
{
    /**
     * @param  float|null  $ipv4PriceMonthly  What the provider charges per month for the server's public IPv4 address
     *                                        in this region, when it bills that separately from the size.
     */
    public function __construct(
        public string $slug,
        public string $name,
        public ?float $ipv4PriceMonthly = null,
    ) {}

    /**
     * @return array{slug: string, name: string, ipv4_price_monthly: float|null}
     */
    public function toArray(): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'ipv4_price_monthly' => $this->ipv4PriceMonthly,
        ];
    }
}
