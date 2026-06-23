<?php

namespace App\Services\Cloudflare;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class CloudflareDnsService
{
    private PendingRequest $http;

    private string $zoneId;

    public function __construct()
    {
        $this->zoneId = config('server.cloudflare_zone_id') ?? '';

        $this->http = Http::baseUrl('https://api.cloudflare.com/client/v4')
            ->timeout(30)
            ->retry(3, 100, throw: false)
            ->withToken(config('server.cloudflare_api_token') ?? '');
    }

    /**
     * Create an A record pointing the domain to the given IP address.
     *
     * @return string The Cloudflare DNS record ID.
     */
    public function createARecord(string $domain, string $ipAddress): string
    {
        $response = $this->http->post("zones/{$this->zoneId}/dns_records", [
            'type' => 'A',
            'name' => $domain,
            'content' => $ipAddress,
            'ttl' => 1,
            'proxied' => false,
        ]);

        if (! $response->successful() || ! $response->json('success')) {
            throw new RuntimeException(
                'Failed to create Cloudflare DNS record: '.$response->body()
            );
        }

        return $response->json('result.id');
    }

    /**
     * Create a TXT record with the given content. Used for the ACME DNS-01
     * challenge when issuing or renewing certificates.
     *
     * @return string The Cloudflare DNS record ID.
     */
    public function createTxtRecord(string $name, string $content, int $ttl = 60): string
    {
        $response = $this->http->post("zones/{$this->zoneId}/dns_records", [
            'type' => 'TXT',
            'name' => $name,
            'content' => $content,
            'ttl' => $ttl,
        ]);

        if (! $response->successful() || ! $response->json('success')) {
            throw new RuntimeException(
                'Failed to create Cloudflare TXT record: '.$response->body()
            );
        }

        return $response->json('result.id');
    }

    /**
     * Delete a DNS record by its Cloudflare record ID.
     */
    public function deleteRecord(string $recordId): void
    {
        $response = $this->http->delete("zones/{$this->zoneId}/dns_records/{$recordId}");

        if (! $response->successful() && $response->status() !== 404) {
            throw new RuntimeException(
                'Failed to delete Cloudflare DNS record: '.$response->body()
            );
        }
    }
}
