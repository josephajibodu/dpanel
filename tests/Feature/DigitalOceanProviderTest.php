<?php

use App\Services\Providers\DigitalOceanProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('creates droplets on Ubuntu 24.04', function () {
    Http::preventStrayRequests();
    Http::fake([
        'api.digitalocean.com/v2/droplets' => Http::response(['droplet' => ['id' => 42, 'name' => 'web-1', 'status' => 'new']], 202),
    ]);
    $provider = new DigitalOceanProvider;
    $provider->setCredentials(['api_token' => 'test-token']);

    $provider->createServer('web-1', 's-2vcpu-4gb', 'fra1', '123');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.digitalocean.com/v2/droplets'
        && $request['image'] === 'ubuntu-24-04-x64');
});

it('asks for every size, not just the first page', function () {
    Http::preventStrayRequests();
    Http::fake(['api.digitalocean.com/v2/sizes*' => Http::response(['sizes' => []])]);
    $provider = new DigitalOceanProvider;
    $provider->setCredentials(['api_token' => 'test-token']);

    $provider->getSizes();

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/sizes') && $request['per_page'] === 200);
});

it('gives each size its hourly price, included traffic and whether its vCPUs are shared', function () {
    Http::preventStrayRequests();
    $size = fn (string $slug, string $description, float $monthly): array => [
        'slug' => $slug,
        'description' => $description,
        'available' => true,
        'vcpus' => 2,
        'memory' => 4096,
        'disk' => 80,
        'transfer' => 4.0,
        'price_monthly' => $monthly,
        'price_hourly' => 0.03571,
        'regions' => ['fra1'],
    ];
    Http::fake(['api.digitalocean.com/v2/sizes*' => Http::response(['sizes' => [
        $size('s-2vcpu-4gb', 'Basic', 24.0),
        $size('g-2vcpu-8gb', 'General Purpose', 63.0),
    ]])]);
    $provider = new DigitalOceanProvider;
    $provider->setCredentials(['api_token' => 'test-token']);

    $sizes = $provider->getSizes()->keyBy('slug');

    expect($sizes['s-2vcpu-4gb'])
        ->category->toBe('Basic')
        ->priceHourly->toBe(0.03571)
        ->transfer->toBe(4.0)
        ->architecture->toBe('x86')
        ->cpuType->toBe('shared')
        ->and($sizes['g-2vcpu-8gb']->cpuType)->toBe('dedicated');
});
