<?php

namespace App\Services\Cloudflare;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class CloudflareDnsService
{
    /**
     * Cloudflare's error code for "a record with this type/name/content
     * already exists" — not a real failure, just an idempotency conflict
     * (e.g. a retried site creation, or a stale record left behind by a
     * delete that failed silently).
     */
    private const IDENTICAL_RECORD_EXISTS = 81058;

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

        return $this->recordIdOrExisting($response, 'A', $domain, 'create Cloudflare DNS record');
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

        return $this->recordIdOrExisting($response, 'TXT', $name, 'create Cloudflare TXT record');
    }

    /**
     * Return the newly created record's ID, or — if Cloudflare rejected the
     * create because an identical record already exists — look up and reuse
     * that existing record's ID instead of failing the whole operation.
     */
    private function recordIdOrExisting(Response $response, string $type, string $name, string $action): string
    {
        if ($response->successful() && $response->json('success')) {
            return $response->json('result.id');
        }

        if ($this->isIdenticalRecordExists($response)) {
            $existingId = $this->findRecordId($type, $name);

            if ($existingId !== null) {
                return $existingId;
            }
        }

        throw new RuntimeException("Failed to {$action}: ".$response->body());
    }

    private function isIdenticalRecordExists(Response $response): bool
    {
        return collect($response->json('errors', []))
            ->contains(fn (array $error) => ($error['code'] ?? null) === self::IDENTICAL_RECORD_EXISTS);
    }

    private function findRecordId(string $type, string $name): ?string
    {
        $response = $this->http->get("zones/{$this->zoneId}/dns_records", [
            'type' => $type,
            'name' => $name,
        ]);

        if (! $response->successful() || ! $response->json('success')) {
            return null;
        }

        return $response->json('result.0.id');
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
