<?php

use App\Models\Site;
use App\Services\PhpRuntimeResolver;

it('resolves all runtime paths for a given PHP version', function () {
    $resolver = new PhpRuntimeResolver;
    $result = $resolver->forVersion('8.3');

    expect($result)->toBe([
        'binary' => 'php8.3',
        'fpm_socket' => '/var/run/php/php8.3-fpm.sock',
        'fpm_service' => 'php8.3-fpm',
        'composer' => 'php8.3 /usr/local/bin/composer',
    ]);
});

it('resolves runtime using a site php_version', function () {
    $site = new Site(['php_version' => '8.2']);
    $resolver = new PhpRuntimeResolver;
    $result = $resolver->forSite($site);

    expect($result['binary'])->toBe('php8.2');
    expect($result['fpm_socket'])->toBe('/var/run/php/php8.2-fpm.sock');
    expect($result['fpm_service'])->toBe('php8.2-fpm');
    expect($result['composer'])->toBe('php8.2 /usr/local/bin/composer');
});

it('defaults to php8.4 when site has no php_version set', function () {
    $site = new Site(['php_version' => null]);
    $resolver = new PhpRuntimeResolver;
    $result = $resolver->forSite($site);

    expect($result['binary'])->toBe('php8.4');
    expect($result['composer'])->toBe('php8.4 /usr/local/bin/composer');
});
