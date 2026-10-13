<?php

namespace App\Services\Certificates;

use Carbon\CarbonImmutable;

/**
 * Reads the expiry of the certificate a server actually serves for a hostname,
 * via a TLS handshake against the server's IP with the hostname as SNI — so
 * the result reflects what nginx hands to clients, not what was recorded at
 * issuance, and isn't affected by DNS or a proxy in front of the server.
 */
class TlsCertificateProbe
{
    public function __construct(
        private int $timeoutSeconds = 10,
    ) {}

    /**
     * Expiry of the certificate served for `$hostname` by `$host`, or null when
     * the server can't be reached or doesn't serve a certificate for that
     * hostname (e.g. nginx falling back to its default snakeoil certificate).
     */
    public function expiresAt(string $host, string $hostname, int $port = 443): ?CarbonImmutable
    {
        // Chain verification is off so an already-expired certificate can
        // still be read; the hostname check stays on so a fallback certificate
        // isn't mistaken for this hostname's.
        $context = stream_context_create(['ssl' => [
            'capture_peer_cert' => true,
            'verify_peer' => false,
            'verify_peer_name' => true,
            'peer_name' => $hostname,
        ]]);

        $address = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? "[{$host}]" : $host;

        $client = @stream_socket_client(
            "ssl://{$address}:{$port}",
            $errorCode,
            $errorMessage,
            $this->timeoutSeconds,
            STREAM_CLIENT_CONNECT,
            $context,
        );

        if ($client === false) {
            return null;
        }

        $certificate = stream_context_get_params($client)['options']['ssl']['peer_certificate'] ?? null;
        fclose($client);

        $parsed = $certificate ? openssl_x509_parse($certificate) : false;

        if (! is_array($parsed) || ! isset($parsed['validTo_time_t'])) {
            return null;
        }

        return CarbonImmutable::createFromTimestamp($parsed['validTo_time_t']);
    }
}
