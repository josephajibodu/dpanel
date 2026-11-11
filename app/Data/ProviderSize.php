<?php

namespace App\Data;

readonly class ProviderSize
{
    /**
     * @param  float  $priceMonthly  The lowest monthly price, before tax, used when a region has no price of its own.
     * @param  string|null  $label  The provider's own name for the size, e.g. "CPX 22".
     * @param  string|null  $category  The family the size belongs to, e.g. "Regular performance".
     * @param  list<string>|null  $regions  Region slugs the provider lists the size in; null means every region.
     * @param  array<string, array{price_monthly?: float, price_hourly?: float|null, transfer?: float|null}>  $regionOverrides  The prices and included
     *                                                                                                                          traffic that differ in a region, keyed by region slug.
     * @param  string  $currency  ISO currency code the prices are in.
     * @param  float|null  $priceHourly  The hourly price that goes with $priceMonthly.
     * @param  float|null  $transfer  Outgoing traffic included each month, in TB.
     * @param  string|null  $architecture  The processor architecture: "x86" or "arm".
     * @param  string|null  $cpuType  Whether the vCPUs are "shared" with other customers or "dedicated" to this server.
     * @param  string|null  $cpuVendor  Who makes the processor, e.g. "AMD".
     * @param  string|null  $diskType  The kind of disk, e.g. "NVMe SSD".
     * @param  list<string>  $unavailableRegions  Listed regions where the provider can't create the size right now, e.g. out of stock.
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
        public array $regionOverrides = [],
        public string $currency = 'USD',
        public ?float $priceHourly = null,
        public ?float $transfer = null,
        public ?string $architecture = null,
        public ?string $cpuType = null,
        public ?string $cpuVendor = null,
        public ?string $diskType = null,
        public array $unavailableRegions = [],
    ) {}

    /**
     * @return array{
     *     slug: string,
     *     label: string|null,
     *     category: string|null,
     *     vcpus: int,
     *     memory: int,
     *     disk: int,
     *     disk_type: string|null,
     *     architecture: string|null,
     *     cpu_type: string|null,
     *     cpu_vendor: string|null,
     *     price_monthly: float,
     *     price_hourly: float|null,
     *     transfer: float|null,
     *     region_overrides: array<string, array{price_monthly?: float, price_hourly?: float|null, transfer?: float|null}>,
     *     regions: list<string>|null,
     *     unavailable_regions: list<string>,
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
            'disk_type' => $this->diskType,
            'architecture' => $this->architecture,
            'cpu_type' => $this->cpuType,
            'cpu_vendor' => $this->cpuVendor,
            'price_monthly' => $this->priceMonthly,
            'price_hourly' => $this->priceHourly,
            'transfer' => $this->transfer,
            'region_overrides' => $this->regionOverrides,
            'regions' => $this->regions,
            'unavailable_regions' => $this->unavailableRegions,
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
