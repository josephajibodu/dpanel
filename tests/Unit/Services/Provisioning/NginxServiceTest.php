<?php

use App\Services\Provisioning\NginxService;

it('includes an https default_server that rejects unmatched hosts', function () {
    $config = NginxService::buildServerConfig();

    expect($config)
        ->toContain('listen 443 ssl default_server;')
        ->toContain('listen [::]:443 ssl default_server;')
        ->toContain('ssl_certificate /etc/ssl/certs/ssl-cert-snakeoil.pem;')
        ->toContain('ssl_certificate_key /etc/ssl/private/ssl-cert-snakeoil.key;')
        ->toContain('return 444;');
});

it('answers every unmatched http request with the no-site page and a 404', function () {
    $config = NginxService::buildServerConfig();

    expect($config)
        ->toContain('listen 80 default_server;')
        ->toContain('listen [::]:80 default_server;')
        ->toContain('root /var/www/flitops;')
        ->toContain('ssi on;')
        ->toContain('error_page 404 /index.html;')
        ->toContain("location / {\n        return 404;\n    }")
        ->not->toContain('fastcgi_pass');
});
