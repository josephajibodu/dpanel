<?php

namespace App\Services\Certificates;

use App\Models\Certificate;
use DateTimeImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Thin wrapper around acme.sh that issues/renews a single (wildcard) cert via
 * the Cloudflare DNS-01 challenge and persists the result to the `certificates`
 * table.
 *
 * Idempotent: a no-op when a Certificate row exists and isn't due for renewal.
 */
class WildcardCertificateIssuer
{
    /**
     * @param  string|null  $storageDirectory  Override the base directory cert files are written to
     *                                         (defaults to storage_path('app/certificates')).
     * @param  string|null  $acmeBinary  Override the acme.sh binary path
     *                                   (defaults to config('server.acme_binary') or 'acme.sh').
     */
    public function __construct(
        private ?string $storageDirectory = null,
        private ?string $acmeBinary = null,
        private int $timeoutSeconds = 180,
    ) {}

    public function issueOrRenew(string $domain): Certificate
    {
        $existing = Certificate::firstWhere('domain', $domain);

        if ($existing && ! $existing->needsRenewal()) {
            return $existing;
        }

        $paths = $this->pathsFor($domain);
        $this->ensureDirectory(dirname($paths['certificate']));

        $this->runAcme($domain, $paths);

        $expiresAt = $this->readExpiry($paths['certificate']);

        return Certificate::updateOrCreate(
            ['domain' => $domain],
            [
                'certificate_path' => $paths['certificate'],
                'private_key_path' => $paths['private_key'],
                'chain_path' => $paths['chain'],
                'expires_at' => $expiresAt,
                'last_renewed_at' => now(),
            ],
        );
    }

    /**
     * @return array{certificate: string, private_key: string, chain: string}
     */
    public function pathsFor(string $domain): array
    {
        $base = $this->storageDirectory ?? storage_path('app/certificates');
        $safeDomain = str_replace(['*', '/'], '_', $domain);
        $directory = "{$base}/{$safeDomain}";

        return [
            'certificate' => "{$directory}/server.crt",
            'private_key' => "{$directory}/server.key",
            'chain' => "{$directory}/chain.pem",
        ];
    }

    private function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create certificate directory {$directory}");
        }
    }

    /**
     * @param  array{certificate: string, private_key: string, chain: string}  $paths
     */
    private function runAcme(string $domain, array $paths): void
    {
        $cloudflareToken = (string) config('server.cloudflare_api_token');
        $cloudflareZoneId = (string) config('server.cloudflare_zone_id');

        if ($cloudflareToken === '') {
            throw new RuntimeException('CLOUDFLARE_API_TOKEN must be set to issue certificates via DNS-01.');
        }

        $binary = $this->acmeBinary ?? (string) config('server.acme_binary', 'acme.sh');

        $command = [
            $binary,
            '--issue',
            '--dns', 'dns_cf',
            '-d', $domain,
            '--fullchain-file', $paths['certificate'],
            '--key-file', $paths['private_key'],
            '--ca-file', $paths['chain'],
            '--server', 'letsencrypt',
            '--force',
        ];

        $result = Process::env([
            'CF_Token' => $cloudflareToken,
            'CF_Zone_ID' => $cloudflareZoneId,
        ])
            ->timeout($this->timeoutSeconds)
            ->run($command);

        if (! $result->successful()) {
            Log::error('acme.sh issuance failed', [
                'domain' => $domain,
                'exit_code' => $result->exitCode(),
                'stderr' => $result->errorOutput(),
            ]);

            throw new RuntimeException(
                "acme.sh failed (exit {$result->exitCode()}): {$result->errorOutput()}"
            );
        }
    }

    private function readExpiry(string $certPath): DateTimeImmutable
    {
        $contents = @file_get_contents($certPath);

        if ($contents === false) {
            throw new RuntimeException("Unable to read issued certificate at {$certPath}");
        }

        $parsed = @openssl_x509_parse($contents);

        if (! is_array($parsed) || ! isset($parsed['validTo_time_t'])) {
            throw new RuntimeException("Unable to parse certificate expiry from {$certPath}");
        }

        return new DateTimeImmutable('@'.$parsed['validTo_time_t']);
    }
}
