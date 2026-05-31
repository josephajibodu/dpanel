<?php

use App\Models\Certificate;
use App\Services\Certificates\WildcardCertificateIssuer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tmpDir = sys_get_temp_dir().'/wildcard-cert-test-'.bin2hex(random_bytes(4));
    @mkdir($this->tmpDir, 0700, true);

    Config::set('server.cloudflare_api_token', 'test-cf-token');
    Config::set('server.cloudflare_zone_id', 'test-zone-id');
});

afterEach(function () {
    if (isset($this->tmpDir)) {
        rrmdir($this->tmpDir);
    }
});

function rrmdir(string $path): void
{
    if (! is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $full = "{$path}/{$entry}";
        is_dir($full) ? rrmdir($full) : @unlink($full);
    }
    @rmdir($path);
}

function selfSignedCertPem(int $daysValid): string
{
    $key = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);
    $csr = openssl_csr_new(['commonName' => 'wildcard.test'], $key);
    $cert = openssl_csr_sign($csr, null, $key, $daysValid);
    openssl_x509_export($cert, $pem);

    return $pem;
}

it('is a no-op when an existing certificate is not yet due for renewal', function () {
    Process::fake();

    Certificate::factory()->create([
        'domain' => '*.example.test',
        'expires_at' => now()->addDays(80),
    ]);

    $result = (new WildcardCertificateIssuer(storageDirectory: $this->tmpDir))
        ->issueOrRenew('*.example.test');

    expect($result->domain)->toBe('*.example.test');
    Process::assertNothingRan();
});

it('invokes acme.sh with the right command + Cloudflare env when issuing for the first time', function () {
    $issuer = new WildcardCertificateIssuer(storageDirectory: $this->tmpDir, acmeBinary: '/fake/acme.sh');
    $paths = $issuer->pathsFor('*.example.test');
    $pem = selfSignedCertPem(80);

    Process::fake(function () use ($paths, $pem) {
        @mkdir(dirname($paths['certificate']), 0700, true);
        file_put_contents($paths['certificate'], $pem);

        return Process::result(output: 'ok', exitCode: 0);
    });

    $cert = $issuer->issueOrRenew('*.example.test');

    expect($cert->domain)->toBe('*.example.test')
        ->and($cert->certificate_path)->toBe($paths['certificate'])
        ->and($cert->private_key_path)->toBe($paths['private_key'])
        ->and($cert->expires_at)->not->toBeNull()
        ->and($cert->last_renewed_at)->not->toBeNull();

    Process::assertRan(function ($process) {
        $command = is_array($process->command) ? $process->command : [];

        return in_array('/fake/acme.sh', $command, true)
            && in_array('--issue', $command, true)
            && in_array('--dns', $command, true)
            && in_array('dns_cf', $command, true)
            && in_array('--server', $command, true)
            && in_array('letsencrypt', $command, true)
            && in_array('-d', $command, true)
            && in_array('*.example.test', $command, true);
    });
});

it('renews when the existing certificate is within the renewal window', function () {
    $issuer = new WildcardCertificateIssuer(storageDirectory: $this->tmpDir);
    $paths = $issuer->pathsFor('*.example.test');

    Certificate::factory()->create([
        'domain' => '*.example.test',
        'expires_at' => now()->addDays(10),
    ]);

    $pem = selfSignedCertPem(80);

    Process::fake(function () use ($paths, $pem) {
        @mkdir(dirname($paths['certificate']), 0700, true);
        file_put_contents($paths['certificate'], $pem);

        return Process::result(exitCode: 0);
    });

    $cert = $issuer->issueOrRenew('*.example.test');

    expect(Certificate::count())->toBe(1)
        ->and(now()->diffInDays($cert->expires_at, false))->toBeGreaterThan(50);
});

it('throws and does not persist a Certificate when acme.sh exits non-zero', function () {
    Process::fake(fn () => Process::result(exitCode: 1, errorOutput: 'CF_Token missing'));

    $issuer = new WildcardCertificateIssuer(storageDirectory: $this->tmpDir);

    expect(fn () => $issuer->issueOrRenew('*.example.test'))
        ->toThrow(RuntimeException::class, 'acme.sh failed');

    expect(Certificate::count())->toBe(0);
});

it('refuses to invoke acme.sh when the Cloudflare API token is missing', function () {
    Config::set('server.cloudflare_api_token', '');
    Process::fake();

    $issuer = new WildcardCertificateIssuer(storageDirectory: $this->tmpDir);

    expect(fn () => $issuer->issueOrRenew('*.example.test'))
        ->toThrow(RuntimeException::class, 'CLOUDFLARE_API_TOKEN');

    Process::assertNothingRan();
});
