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
