<?php

namespace App\Data;

readonly class ProviderRegion
{
    public function __construct(
        public string $slug,
        public string $name,
    ) {}

    /**
     * @return array{slug: string, name: string}
     */
    public function toArray(): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
        ];
    }
}
