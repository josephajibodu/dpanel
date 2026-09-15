<?php

namespace App\Services\Storage;

use App\Actions\Database\EscapesShell;
use App\Contracts\StorageProviderContract;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

abstract class AbstractS3CompatibleDriver implements StorageProviderContract
{
    use EscapesShell;

    /** @var array<string, string> */
    protected array $credentials = [];

    public function setCredentials(array $credentials): void
    {
        $this->credentials = $credentials;
    }

    public function validateCredentials(): bool
    {
        try {
            $marker = '.flitops-connectivity-check-'.Str::random(8);

            $this->disk()->put($marker, 'ok');
            $this->disk()->delete($marker);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function putStream(string $path, $stream): void
    {
        $this->disk()->put($path, $stream);
    }

    public function delete(string $path): void
    {
        $this->disk()->delete($path);
    }

    public function temporaryUrl(string $path, \DateTimeInterface $expiry): string
    {
        return $this->disk()->temporaryUrl($path, $expiry);
    }

    public function size(string $path): ?int
    {
        try {
            return $this->disk()->size($path);
        } catch (\Throwable) {
            return null;
        }
    }

    public function bucket(): string
    {
        return $this->credentials['bucket'] ?? '';
    }

    public function envPrefix(): string
    {
        return sprintf(
            'AWS_ACCESS_KEY_ID=%s AWS_SECRET_ACCESS_KEY=%s',
            $this->escapeForShell($this->credentials['access_key_id'] ?? ''),
            $this->escapeForShell($this->credentials['secret_access_key'] ?? ''),
        );
    }

    public function awsCliArgs(): string
    {
        $config = $this->diskConfig();

        $args = '--region='.$this->escapeForShell($config['region']);

        if ($config['endpoint']) {
            $args .= ' --endpoint-url='.$this->escapeForShell($config['endpoint']);
        }

        return $args;
    }

    /**
     * Build the disk config's region/endpoint/path-style for this provider
     * type from the connected credentials.
     *
     * @return array{region: string, endpoint: ?string, use_path_style_endpoint: bool}
     */
    abstract protected function diskConfig(): array;

    protected function disk(): Filesystem
    {
        $config = $this->diskConfig();

        return Storage::build([
            'driver' => 's3',
            'key' => $this->credentials['access_key_id'] ?? '',
            'secret' => $this->credentials['secret_access_key'] ?? '',
            'region' => $config['region'],
            'bucket' => $this->bucket(),
            'endpoint' => $config['endpoint'],
            'use_path_style_endpoint' => $config['use_path_style_endpoint'],
            'throw' => true,
        ]);
    }
}
