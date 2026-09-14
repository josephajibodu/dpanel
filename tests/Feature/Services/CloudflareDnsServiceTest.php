<?php

use App\Services\Cloudflare\CloudflareDnsService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'server.cloudflare_api_token' => 'test-token',
        'server.cloudflare_zone_id' => 'zone-abc-123',
    ]);
});

describe('createARecord', function () {
    it('creates an A record and returns the record ID', function () {
        Http::fake([
            'api.cloudflare.com/client/v4/zones/zone-abc-123/dns_records' => Http::response([
                'success' => true,
                'result' => [
                    'id' => 'dns-record-xyz',
                    'type' => 'A',
                    'name' => 'myapp.flitops.xyz',
                    'content' => '203.0.113.42',
                ],
            ]),
        ]);

        $service = new CloudflareDnsService;
        $recordId = $service->createARecord('myapp.flitops.xyz', '203.0.113.42');

        expect($recordId)->toBe('dns-record-xyz');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.cloudflare.com/client/v4/zones/zone-abc-123/dns_records'
                && $request->method() === 'POST'
                && $request['type'] === 'A'
                && $request['name'] === 'myapp.flitops.xyz'
                && $request['content'] === '203.0.113.42'
                && $request['proxied'] === false
                && $request->hasHeader('Authorization', 'Bearer test-token');
        });
    });

    it('throws on API failure', function () {
        Http::fake([
            'api.cloudflare.com/client/v4/zones/zone-abc-123/dns_records' => Http::response([
                'success' => false,
                'errors' => [['message' => 'Invalid zone']],
            ], 400),
        ]);

        $service = new CloudflareDnsService;
        $service->createARecord('fail.flitops.xyz', '1.2.3.4');
    })->throws(RuntimeException::class, 'Failed to create Cloudflare DNS record');

    it('reuses the existing record ID instead of failing when an identical record already exists', function () {
        Http::fake(function ($request) {
            if ($request->method() === 'POST') {
                return Http::response([
                    'success' => false,
                    'errors' => [['code' => 81058, 'message' => 'An identical record already exists.']],
                ], 400);
            }

            expect($request->method())->toBe('GET')
                ->and($request['type'])->toBe('A')
                ->and($request['name'])->toBe('myapp.flitops.xyz');

            return Http::response([
                'success' => true,
                'result' => [['id' => 'existing-record-id']],
            ]);
        });

        $service = new CloudflareDnsService;
        $recordId = $service->createARecord('myapp.flitops.xyz', '203.0.113.42');

        expect($recordId)->toBe('existing-record-id');
    });

    it('throws when an identical record exists but cannot be looked up', function () {
        Http::fake(function ($request) {
            if ($request->method() === 'POST') {
                return Http::response([
                    'success' => false,
                    'errors' => [['code' => 81058, 'message' => 'An identical record already exists.']],
                ], 400);
            }

            return Http::response(['success' => false, 'errors' => []], 500);
        });

        $service = new CloudflareDnsService;
        $service->createARecord('myapp.flitops.xyz', '203.0.113.42');
    })->throws(RuntimeException::class, 'Failed to create Cloudflare DNS record');
});

describe('deleteRecord', function () {
    it('deletes a DNS record by ID', function () {
        Http::fake([
            'api.cloudflare.com/client/v4/zones/zone-abc-123/dns_records/dns-record-xyz' => Http::response([
                'success' => true,
                'result' => ['id' => 'dns-record-xyz'],
            ]),
        ]);

        $service = new CloudflareDnsService;
        $service->deleteRecord('dns-record-xyz');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'dns_records/dns-record-xyz')
                && $request->method() === 'DELETE';
        });
    });

    it('does not throw when record is already gone (404)', function () {
        Http::fake([
            'api.cloudflare.com/client/v4/zones/zone-abc-123/dns_records/gone-record' => Http::response(
                ['success' => false, 'errors' => [['message' => 'Not found']]],
                404,
            ),
        ]);

        $service = new CloudflareDnsService;
        $service->deleteRecord('gone-record');

        expect(true)->toBeTrue();
    });

    it('throws on unexpected API failure', function () {
        Http::fake([
            'api.cloudflare.com/client/v4/zones/zone-abc-123/dns_records/bad-record' => Http::response(
                ['success' => false, 'errors' => [['message' => 'Server error']]],
                500,
            ),
        ]);

        $service = new CloudflareDnsService;
        $service->deleteRecord('bad-record');
    })->throws(RuntimeException::class, 'Failed to delete Cloudflare DNS record');
});
