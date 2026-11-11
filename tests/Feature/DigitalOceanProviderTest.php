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
