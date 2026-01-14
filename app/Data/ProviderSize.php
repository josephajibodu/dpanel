<?php

namespace App\Data;

readonly class ProviderSize
{
    public function __construct(
        public string $slug,
        public int $vcpus,
        public int $memory,
        public int $disk,
        public float $priceMonthly,
    ) {}

    /**
     * @return array{slug: string, vcpus: int, memory: int, disk: int, price_monthly: float, description: string}
     */
    public function toArray(): array
    {
        return [
            'slug' => $this->slug,
            'vcpus' => $this->vcpus,
            'memory' => $this->memory,
            'disk' => $this->disk,
            'price_monthly' => $this->priceMonthly,
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
