<?php

namespace App\Services\Storage;

class CloudflareR2Driver extends AbstractS3CompatibleDriver
{
    protected function diskConfig(): array
    {
        $accountId = $this->credentials['account_id'] ?? '';

        return [
            'region' => 'auto',
            'endpoint' => "https://{$accountId}.r2.cloudflarestorage.com",
            'use_path_style_endpoint' => true,
        ];
    }
}
