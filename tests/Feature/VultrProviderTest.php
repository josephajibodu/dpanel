<?php

use App\Services\Providers\VultrProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('creates instances on Ubuntu 24.04', function () {
    Http::preventStrayRequests();
    Http::fake([
        'api.vultr.com/v2/instances' => Http::response(['instance' => ['id' => 'abc-123', 'label' => 'web-1', 'status' => 'pending']], 202),
    ]);
    $provider = new VultrProvider;
    $provider->setCredentials(['api_token' => 'test-token']);

    $provider->createServer('web-1', 'vc2-2c-4gb', 'fra', 'key-123');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.vultr.com/v2/instances'
        && $request['os_id'] === 2284);
});

it('creates instances without paid automatic backups', function () {
    Http::preventStrayRequests();
    Http::fake([
        'api.vultr.com/v2/instances' => Http::response(['instance' => ['id' => 'abc-123', 'label' => 'web-1', 'status' => 'pending']], 202),
    ]);
    $provider = new VultrProvider;
    $provider->setCredentials(['api_token' => 'test-token']);

    $provider->createServer('web-1', 'vc2-2c-4gb', 'fra', 'key-123');

    Http::assertSent(fn (Request $request) => $request['backups'] === 'disabled');
});

/**
 * A plan as Vultr's API lists it, with the given fields changed.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function vultrPlan(string $id, array $overrides = []): array
{
    return [
        'id' => $id,
        'vcpu_count' => 1,
        'ram' => 1024,
        'disk' => 25,
        'bandwidth' => 2048,
        'monthly_cost' => 6,
        'hourly_cost' => 0.008,
        'cpu_vendor' => 'AMD',
        'locations' => ['fra', 'sao'],
        'location_cost' => [],
        ...$overrides,
    ];
}

it('asks for every plan, not just the first page', function () {
    Http::preventStrayRequests();
    Http::fake(['api.vultr.com/v2/plans*' => Http::response(['plans' => []])]);
    $provider = new VultrProvider;
    $provider->setCredentials(['api_token' => 'test-token']);

    $provider->getSizes();

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/plans') && $request['per_page'] === 500);
});

it('gives each plan its family, specifications, traffic and the regions that cost more', function () {
    Http::preventStrayRequests();
    Http::fake(['api.vultr.com/v2/plans*' => Http::response(['plans' => [
        vultrPlan('vhp-1c-1gb-amd', ['location_cost' => ['sao' => ['monthly_cost' => 9, 'hourly_cost' => 0.012]]]),
        vultrPlan('voc-c-1c-2gb-25s-amd', ['monthly_cost' => 28]),
    ]])]);
    $provider = new VultrProvider;
    $provider->setCredentials(['api_token' => 'test-token']);

    $sizes = $provider->getSizes()->keyBy('slug');

    expect($sizes['vhp-1c-1gb-amd'])
        ->category->toBe('High performance')
        ->cpuType->toBe('shared')
        ->cpuVendor->toBe('AMD')
        ->architecture->toBe('x86')
        ->diskType->toBe('NVMe SSD')
        ->priceMonthly->toBe(6.0)
        ->priceHourly->toBe(0.008)
        ->transfer->toBe(2.0)
        ->regions->toBe(['fra', 'sao'])
        ->regionOverrides->toBe(['sao' => ['price_monthly' => 9.0, 'price_hourly' => 0.012]])
        ->and($sizes['voc-c-1c-2gb-25s-amd'])
        ->category->toBe('CPU optimized')
        ->cpuType->toBe('dedicated');
});

it('leaves out plans a server cannot be set up on', function (array $plan) {
    Http::preventStrayRequests();
    Http::fake(['api.vultr.com/v2/plans*' => Http::response(['plans' => [$plan, vultrPlan('vc2-1c-1gb')]])]);
    $provider = new VultrProvider;
    $provider->setCredentials(['api_token' => 'test-token']);

    expect($provider->getSizes()->pluck('slug')->all())->toBe(['vc2-1c-1gb']);
})->with([
    'IPv6-only plan' => [vultrPlan('vc2-1c-0.5gb-v6')],
    'free plan' => [vultrPlan('vc2-1c-0.5gb-free', ['monthly_cost' => 0])],
    'plan sold in no region' => [vultrPlan('vc2-24c-96gb', ['locations' => []])],
    'GPU plan' => [vultrPlan('vcg-a16-6c-60g-16vram')],
]);
