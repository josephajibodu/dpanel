<?php

namespace App\Data;

readonly class ProviderSize
{
    /**
     * @param  float  $priceMonthly  The lowest monthly price, before tax, used when a region has no price of its own.
     * @param  string|null  $label  The provider's own name for the size, e.g. "CPX 22".
     * @param  string|null  $category  The family the size belongs to, e.g. "Regular performance".
     * @param  list<string>|null  $regions  Region slugs the size can be created in; null means every region.
     * @param  array<string, float>  $regionPrices  Monthly price before tax per region slug, where it differs by region.
     * @param  string  $currency  ISO currency code the prices are in.
     */
    public function __construct(
        public string $slug,
        public int $vcpus,
        public int $memory,
        public int $disk,
        public float $priceMonthly,
        public ?string $label = null,
        public ?string $category = null,
        public ?array $regions = null,
        public array $regionPrices = [],
        public string $currency = 'USD',
    ) {}

    /**
     * @return array{
     *     slug: string,
     *     label: string|null,
     *     category: string|null,
     *     vcpus: int,
     *     memory: int,
     *     disk: int,
     *     price_monthly: float,
     *     region_prices: array<string, float>,
     *     regions: list<string>|null,
     *     currency: string,
     *     description: string
     * }
     */
    public function toArray(): array
    {
        return [
            'slug' => $this->slug,
            'label' => $this->label,
            'category' => $this->category,
            'vcpus' => $this->vcpus,
            'memory' => $this->memory,
            'disk' => $this->disk,
            'price_monthly' => $this->priceMonthly,
            'region_prices' => $this->regionPrices,
            'regions' => $this->regions,
            'currency' => $this->currency,
            'description' => $this->description(),
        ];
    }

    public function description(): string
    {
        $memoryGb = $this->memory / 1024;

        return sprintf(
            '%d vCPU, %s GB RAM, %d GB SSD - $%s/mo',
            $this->vcpus,
            $memoryGb >= 1 ? number_format($memoryGb, $memoryGb == (int) $memoryGb ? 0 : 1) : ($this->memory.' MB'),
            $this->disk,
            number_format($this->priceMonthly, 2)
        );
    }
}
