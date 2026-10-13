<?php

use App\Services\Certificates\TlsCertificateProbe;

/**
 * Serves a freshly generated self-signed certificate over TLS on a random
 * local port from a child PHP process.
 *
 * @return array{process: resource, port: int, expires_at: int, pem_path: string}
 */
function startTlsProbeServer(string $commonName, int $daysValid): array
{
    $privateKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $certificate = openssl_csr_sign(openssl_csr_new(['commonName' => $commonName], $privateKey), null, $privateKey, $daysValid);
    openssl_x509_export($certificate, $certificatePem);
    openssl_pkey_export($privateKey, $privateKeyPem);

    $pemPath = tempnam(sys_get_temp_dir(), 'tls-probe-');
    file_put_contents($pemPath, $certificatePem.$privateKeyPem);

    $serverCode = '$context = stream_context_create(["ssl" => ["local_cert" => '.var_export($pemPath, true).']]);'
        .'$server = stream_socket_server("tls://127.0.0.1:0", $errorCode, $errorMessage, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);'
        .'fwrite(STDOUT, stream_socket_get_name($server, false).PHP_EOL);'
        .'while ($connection = @stream_socket_accept($server, 5)) { fclose($connection); }';

    $process = proc_open([PHP_BINARY, '-r', $serverCode], [1 => ['pipe', 'w']], $pipes);
    $address = trim((string) fgets($pipes[1]));

    return [
        'process' => $process,
        'port' => (int) substr($address, strrpos($address, ':') + 1),
        'expires_at' => openssl_x509_parse($certificatePem)['validTo_time_t'],
        'pem_path' => $pemPath,
    ];
}

afterEach(function () {
    foreach ($this->tlsServers ?? [] as $server) {
        proc_terminate($server['process']);
        proc_close($server['process']);
        @unlink($server['pem_path']);
    }
});

it('reads the expiry of the certificate served for the hostname', function () {
    $server = $this->tlsServers[] = startTlsProbeServer('shop.example.com', 30);

    $expiresAt = (new TlsCertificateProbe(timeoutSeconds: 5))->expiresAt('127.0.0.1', 'shop.example.com', $server['port']);

    expect($expiresAt?->getTimestamp())->toBe($server['expires_at']);
});

it('still reads the expiry of a certificate that has already expired', function () {
    $server = $this->tlsServers[] = startTlsProbeServer('shop.example.com', 0);
    sleep(1);

    $expiresAt = (new TlsCertificateProbe(timeoutSeconds: 5))->expiresAt('127.0.0.1', 'shop.example.com', $server['port']);

    expect($expiresAt?->getTimestamp())->toBe($server['expires_at'])
        ->and($expiresAt->isPast())->toBeTrue();
});

it('returns null when the server serves a certificate for a different hostname', function () {
    $server = $this->tlsServers[] = startTlsProbeServer('localhost', 3650);

    $expiresAt = (new TlsCertificateProbe(timeoutSeconds: 5))->expiresAt('127.0.0.1', 'shop.example.com', $server['port']);

    expect($expiresAt)->toBeNull();
});

it('returns null when nothing is listening', function () {
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $address = stream_socket_get_name($socket, false);
    fclose($socket);

    $port = (int) substr($address, strrpos($address, ':') + 1);

    expect((new TlsCertificateProbe(timeoutSeconds: 5))->expiresAt('127.0.0.1', 'shop.example.com', $port))->toBeNull();
});
