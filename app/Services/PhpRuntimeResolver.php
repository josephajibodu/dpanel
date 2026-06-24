<?php

namespace App\Services;

use App\Models\Site;

class PhpRuntimeResolver
{
    /**
     * Resolve all PHP runtime paths for a site from its php_version field.
     *
     * @return array{binary: string, fpm_socket: string, fpm_service: string, composer: string}
     */
    public function forSite(Site $site): array
    {
        return $this->forVersion($site->php_version ?? '8.4');
    }

    /**
     * @return array{binary: string, fpm_socket: string, fpm_service: string, composer: string}
     */
    public function forVersion(string $version): array
    {
        return [
            'binary' => "php{$version}",
            'fpm_socket' => '/run/php/php'.$version.'-fpm-'.config('server.user').'.sock',
            'fpm_service' => "php{$version}-fpm",
            'composer' => "php{$version} /usr/local/bin/composer",
        ];
    }
}
