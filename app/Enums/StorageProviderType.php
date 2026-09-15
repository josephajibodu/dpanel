<?php

namespace App\Enums;

enum StorageProviderType: string
{
    case CloudflareR2 = 'cloudflare_r2';
    case S3 = 's3';

    public function label(): string
    {
        return match ($this) {
            self::CloudflareR2 => 'Cloudflare R2',
            self::S3 => 'Amazon S3',
        };
    }

    /**
     * The credential field names this provider type expects, in the order
     * the connect form should display them.
     *
     * @return array<int, string>
     */
    public function credentialFields(): array
    {
        return match ($this) {
            self::CloudflareR2 => ['account_id', 'access_key_id', 'secret_access_key', 'bucket'],
            self::S3 => ['access_key_id', 'secret_access_key', 'bucket', 'region'],
        };
    }
}
