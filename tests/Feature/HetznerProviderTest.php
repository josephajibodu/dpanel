<?php

use App\Services\Providers\HetznerProvider;
use Illuminate\Support\Facades\Http;

it('lists sizes without the retired ones, now that Hetzner no longer sends a deprecated flag', function () {
    Http::preventStrayRequests();
    $serverType = fn (string $name, ?array $deprecation): array => [
        'name' => $name,
        'cores' => 2,
        'memory' => 4,
        'disk' => 40,
        'deprecation' => $deprecation,
        'locations' => [['name' => 'fsn1', 'available' => true, 'deprecation' => null]],
        'prices' => [['location' => 'fsn1', 'price_monthly' => ['net' => '4.99', 'gross' => '5.94']]],
    ];
    Http::fake([
        'api.hetzner.cloud/v1/pricing' => Http::response(['pricing' => ['currency' => 'EUR']]),
        'api.hetzner.cloud/v1/server_types' => Http::response(['server_types' => [
            $serverType('cx23', null),
            $serverType('cx11', ['announced' => '2024-01-01T00:00:00+00:00', 'unavailable_after' => '2024-04-01T00:00:00+00:00']),
        ]]),
    ]);
    $provider = new HetznerProvider;
    $provider->setCredentials(['api_token' => 'test-token']);

    $sizes = $provider->getSizes();

    expect($sizes->pluck('slug')->all())->toBe(['cx23'])
        ->and($sizes->first()->memory)->toBe(4096);
});

it('prices each size per location before tax, only where it can be created', function () {
    Http::preventStrayRequests();
    Http::fake([
        'api.hetzner.cloud/v1/pricing' => Http::response(['pricing' => ['currency' => 'USD']]),
        'api.hetzner.cloud/v1/server_types' => Http::response(['server_types' => [[
            'name' => 'cax11',
            'description' => 'CAX 11',
            'category' => 'cost_optimized',
            'architecture' => 'arm',
            'cores' => 2,
            'memory' => 4,
            'disk' => 40,
            'deprecation' => null,
            'locations' => [
                ['name' => 'fsn1', 'available' => true, 'deprecation' => null],
                ['name' => 'hel1', 'available' => true, 'deprecation' => null],
                ['name' => 'nbg1', 'available' => false, 'deprecation' => null],
            ],
            'prices' => [
                ['location' => 'fsn1', 'price_monthly' => ['net' => '4.49', 'gross' => '5.34']],
                ['location' => 'hel1', 'price_monthly' => ['net' => '4.99', 'gross' => '5.94']],
                ['location' => 'nbg1', 'price_monthly' => ['net' => '3.99', 'gross' => '4.75']],
            ],
        ]]]),
    ]);
    $provider = new HetznerProvider;
    $provider->setCredentials(['api_token' => 'test-token']);

    $size = $provider->getSizes()->sole();

    expect($size)
        ->label->toBe('CAX 11')
        ->category->toBe('Cost-optimized (Arm)')
        ->regions->toBe(['fsn1', 'hel1'])
        ->regionPrices->toBe(['fsn1' => 4.49, 'hel1' => 4.99])
        ->priceMonthly->toBe(4.49)
        ->currency->toBe('USD');
});

it('gives each location the price of its public IPv4 address', function () {
    Http::preventStrayRequests();
    Http::fake([
        'api.hetzner.cloud/v1/pricing' => Http::response(['pricing' => [
            'currency' => 'USD',
            'primary_ips' => [['type' => 'ipv4', 'prices' => [
                ['location' => 'fsn1', 'price_monthly' => ['net' => '0.60', 'gross' => '0.71']],
            ]]],
        ]]),
        'api.hetzner.cloud/v1/locations' => Http::response(['locations' => [
            ['name' => 'fsn1', 'description' => 'Falkenstein DC Park 1', 'city' => 'Falkenstein'],
            ['name' => 'ash', 'description' => 'Ashburn, VA', 'city' => 'Ashburn'],
        ]]),
    ]);
    $provider = new HetznerProvider;
    $provider->setCredentials(['api_token' => 'test-token']);

    $regions = $provider->getRegions()->keyBy('slug');

    expect($regions['fsn1']->ipv4PriceMonthly)->toBe(0.6)
        ->and($regions['ash']->ipv4PriceMonthly)->toBeNull();
});
