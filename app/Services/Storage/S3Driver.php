<?php

namespace App\Services\Storage;

class S3Driver extends AbstractS3CompatibleDriver
{
    protected function diskConfig(): array
    {
        return [
            'region' => $this->credentials['region'] ?? 'us-east-1',
            'endpoint' => null,
            'use_path_style_endpoint' => false,
        ];
    }
}
