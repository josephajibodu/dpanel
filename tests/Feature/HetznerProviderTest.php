<?php

use App\Services\Providers\HetznerProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('creates servers on Ubuntu 24.04', function () {
    Http::preventStrayRequests();
    Http::fake([
        'api.hetzner.cloud/v1/servers' => Http::response(['server' => ['id' => 42, 'name' => 'web-1', 'status' => 'initializing']], 201),
    ]);
    $provider = new HetznerProvider;
    $provider->setCredentials(['api_token' => 'test-token']);

    $provider->createServer('web-1', 'cx23', 'nbg1', '123');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.hetzner.cloud/v1/servers'
        && $request['image'] === 'ubuntu-24.04');
});

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
        'api.hetzner.cloud/v1/server_types*' => Http::response(['server_types' => [
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

it('asks for every server type, not just the first page', function () {
    Http::preventStrayRequests();
    Http::fake([
        'api.hetzner.cloud/v1/pricing' => Http::response(['pricing' => ['currency' => 'EUR']]),
        'api.hetzner.cloud/v1/server_types*' => Http::response(['server_types' => []]),
    ]);
    $provider = new HetznerProvider;
    $provider->setCredentials(['api_token' => 'test-token']);

    $provider->getSizes();

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/server_types') && $request['per_page'] === 50);
});

it('gives each size its price and included traffic per location, before tax', function () {
    Http::preventStrayRequests();
    $price = fn (string $location, string $monthly, string $hourly, int $trafficTb): array => [
        'location' => $location,
        'price_monthly' => ['net' => $monthly, 'gross' => '99.99'],
        'price_hourly' => ['net' => $hourly, 'gross' => '9.99'],
        'included_traffic' => $trafficTb * 1024 ** 4,
    ];
    Http::fake([
        'api.hetzner.cloud/v1/pricing' => Http::response(['pricing' => ['currency' => 'USD']]),
        'api.hetzner.cloud/v1/server_types*' => Http::response(['server_types' => [[
            'name' => 'cpx22',
            'description' => 'CPX 22',
            'category' => 'regular_purpose',
            'architecture' => 'x86',
            'cpu_type' => 'shared',
            'storage_type' => 'local',
            'cores' => 2,
            'memory' => 4,
            'disk' => 80,
            'deprecation' => null,
            'locations' => [
                ['name' => 'fsn1', 'available' => true, 'deprecation' => null],
                ['name' => 'sin', 'available' => true, 'deprecation' => null],
                ['name' => 'ash', 'available' => true, 'deprecation' => ['announced' => '2025-10-16T06:00:00Z', 'unavailable_after' => '2025-12-31T23:59:59Z']],
            ],
            'prices' => [
                $price('sin', '30.99', '0.0497', 1),
                $price('fsn1', '22.99', '0.0368', 20),
                $price('ash', '20.49', '0.0328', 1),
            ],
        ]]]),
    ]);
    $provider = new HetznerProvider;
    $provider->setCredentials(['api_token' => 'test-token']);

    $size = $provider->getSizes()->sole();

    expect($size)
        ->label->toBe('CPX 22')
        ->category->toBe('Regular performance')
        ->regions->toBe(['fsn1', 'sin'])
        ->regionOverrides->toBe([
            'sin' => ['price_monthly' => 30.99, 'price_hourly' => 0.0497, 'transfer' => 1.0],
            'fsn1' => ['price_monthly' => 22.99, 'price_hourly' => 0.0368, 'transfer' => 20.0],
        ])
        ->priceMonthly->toBe(22.99)
        ->priceHourly->toBe(0.0368)
        ->transfer->toBe(20.0)
        ->currency->toBe('USD');
});

it('describes the processor and disk of each size', function () {
    Http::preventStrayRequests();
    Http::fake([
        'api.hetzner.cloud/v1/pricing' => Http::response(['pricing' => ['currency' => 'EUR']]),
        'api.hetzner.cloud/v1/server_types*' => Http::response(['server_types' => [[
            'name' => 'cax11',
            'category' => 'cost_optimized',
            'architecture' => 'arm',
            'cpu_type' => 'shared',
            'storage_type' => 'local',
            'cores' => 2,
            'memory' => 4,
            'disk' => 40,
            'deprecation' => null,
            'locations' => [['name' => 'fsn1', 'available' => true, 'deprecation' => null]],
            'prices' => [['location' => 'fsn1', 'price_monthly' => ['net' => '4.49', 'gross' => '5.34']]],
        ]]]),
    ]);
    $provider = new HetznerProvider;
    $provider->setCredentials(['api_token' => 'test-token']);

    expect($provider->getSizes()->sole())
        ->category->toBe('Cost-optimized')
        ->architecture->toBe('arm')
        ->cpuType->toBe('shared')
        ->diskType->toBe('NVMe SSD');
});

it('keeps a size that is out of stock in a location and marks it unavailable there', function () {
    Http::preventStrayRequests();
    Http::fake([
        'api.hetzner.cloud/v1/pricing' => Http::response(['pricing' => ['currency' => 'EUR']]),
        'api.hetzner.cloud/v1/server_types*' => Http::response(['server_types' => [[
            'name' => 'cx23',
            'cores' => 2,
            'memory' => 4,
            'disk' => 40,
            'deprecation' => null,
            'locations' => [
                ['name' => 'fsn1', 'available' => true, 'deprecation' => null],
                ['name' => 'nbg1', 'available' => false, 'deprecation' => null],
            ],
            'prices' => [
                ['location' => 'fsn1', 'price_monthly' => ['net' => '4.49', 'gross' => '5.34']],
                ['location' => 'nbg1', 'price_monthly' => ['net' => '4.49', 'gross' => '5.34']],
            ],
        ]]]),
    ]);
    $provider = new HetznerProvider;
    $provider->setCredentials(['api_token' => 'test-token']);

    expect($provider->getSizes()->sole())
        ->regions->toBe(['fsn1', 'nbg1'])
        ->unavailableRegions->toBe(['nbg1']);
});

it('gives each location the price of its public IPv4 address and of extra traffic', function () {
    Http::preventStrayRequests();
    Http::fake([
        'api.hetzner.cloud/v1/pricing' => Http::response(['pricing' => [
            'currency' => 'USD',
            'primary_ips' => [['type' => 'ipv4', 'prices' => [
                ['location' => 'fsn1', 'price_monthly' => ['net' => '0.60', 'gross' => '0.71']],
            ]]],
            'server_types' => [['name' => 'cpx22', 'prices' => [
                ['location' => 'fsn1', 'price_per_tb_traffic' => ['net' => '1.20', 'gross' => '1.43']],
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

    expect($regions['fsn1'])
        ->extraCharges->toBe([['label' => 'Public IPv4 address', 'price_monthly' => 0.6]])
        ->trafficPricePerTb->toBe(1.2)
        ->and($regions['ash'])
        ->extraCharges->toBe([])
        ->trafficPricePerTb->toBeNull();
});
