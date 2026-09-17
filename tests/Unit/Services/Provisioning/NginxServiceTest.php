<?php

use App\Services\Provisioning\NginxService;

it('includes an https default_server that rejects unmatched hosts', function () {
    $config = NginxService::buildServerConfig('artisan', '8.3');

    expect($config)
        ->toContain('listen 443 ssl default_server;')
        ->toContain('listen [::]:443 ssl default_server;')
        ->toContain('ssl_certificate /etc/ssl/certs/ssl-cert-snakeoil.pem;')
        ->toContain('ssl_certificate_key /etc/ssl/private/ssl-cert-snakeoil.key;')
        ->toContain('return 444;');
});

it('still includes the plain-http default_server for the placeholder page', function () {
    $config = NginxService::buildServerConfig('artisan', '8.3');

    expect($config)
        ->toContain('listen 80 default_server;')
        ->toContain('listen [::]:80 default_server;')
        ->toContain('root /home/artisan/sites;')
        ->toContain('fastcgi_pass unix:/run/php/php8.3-fpm-artisan.sock;');
});
