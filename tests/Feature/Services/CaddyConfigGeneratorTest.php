<?php

use App\Enums\ProjectType;
use App\Enums\WwwRedirect;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Services\Caddy\CaddyConfigGenerator;

beforeEach(function () {
    config(['server.user' => 'flitops', 'app.name' => 'FlitOps']);

    $this->server = Server::factory()->create(['web_server' => 'caddy']);
});

function caddySiteDomain(Site $site): SiteDomain
{
    return $site->domains()->where('is_primary', true)->firstOrFail();
}

it('serves a Laravel site over HTTPS through PHP-FPM, following the current release symlink', function () {
    $site = Site::factory()->forServer($this->server)->create(['domain' => 'myapp.com', 'php_version' => '8.4']);
    $domain = caddySiteDomain($site);

    $config = app(CaddyConfigGenerator::class)->generate($site, $domain, ssl: true);

    expect($config)
        ->toContain("myapp.com {\n\troot * /home/flitops/myapp.com/current/public")
        ->toContain("\tphp_fastcgi unix//run/php/php8.4-fpm-flitops.sock {\n\t\tresolve_root_symlink\n\t}\n\tfile_server")
        ->toContain('Strict-Transport-Security "max-age=63072000"')
        ->toContain('respond @hidden 403')
        ->toContain("\timport {$domain->nginxSnippetsBasePath()}/server/*.conf\n}")
        ->toContain("import {$domain->nginxSnippetsBasePath()}/after/*.conf")
        ->not->toContain('tls ')
        ->not->toContain('http://');
});

it('uses http:// addresses without HSTS when the domain has no HTTPS yet', function () {
    $site = Site::factory()->forServer($this->server)->create(['domain' => 'myapp.com']);

    $config = app(CaddyConfigGenerator::class)->generate($site, caddySiteDomain($site), ssl: false);

    expect($config)
        ->toContain('http://myapp.com {')
        ->not->toContain('Strict-Transport-Security');
});

it('serves an installed certificate, wildcard included, when given a certificate folder', function () {
    $site = Site::factory()->forServer($this->server)->create(['domain' => 'myapp.com']);
    $domain = caddySiteDomain($site);
    $domain->update(['wildcard_enabled' => true]);

    $config = app(CaddyConfigGenerator::class)->generate($site, $domain, true, '/etc/caddy/ssl/domains/1/2');

    expect($config)
        ->toContain('myapp.com, *.myapp.com {')
        ->toContain("\ttls /etc/caddy/ssl/domains/1/2/server.crt /etc/caddy/ssl/domains/1/2/server.key\n");
});

it('leaves out the wildcard when Caddy would have to obtain the certificate itself', function () {
    $site = Site::factory()->forServer($this->server)->create(['domain' => 'myapp.com']);
    $domain = caddySiteDomain($site);
    $domain->update(['wildcard_enabled' => true]);

    $config = app(CaddyConfigGenerator::class)->generate($site, $domain, ssl: true);

    expect($config)
        ->toContain("myapp.com {\n")
        ->not->toContain('*.myapp.com');
});

it('serves a static site without PHP', function () {
    $site = Site::factory()->forServer($this->server)->create(['domain' => 'docs.com', 'project_type' => ProjectType::StaticHtml]);

    $config = app(CaddyConfigGenerator::class)->generate($site, caddySiteDomain($site), ssl: true);

    expect($config)
        ->toContain("\tfile_server")
        ->not->toContain('php_fastcgi');
});

it('blocks wp-config.php on WordPress sites', function () {
    $site = Site::factory()->forServer($this->server)->create(['domain' => 'blog.com', 'project_type' => ProjectType::WordPress]);

    $config = app(CaddyConfigGenerator::class)->generate($site, caddySiteDomain($site), ssl: true);

    expect($config)->toContain("\trespond /wp-config.php 403");
});

it('redirects between www and the bare domain', function (WwwRedirect $redirect, string $siteAddress, string $redirectBlock) {
    $site = Site::factory()->forServer($this->server)->create(['domain' => 'myapp.com']);
    $domain = caddySiteDomain($site);
    $domain->update(['www_redirect' => $redirect]);

    $config = app(CaddyConfigGenerator::class)->generate($site, $domain, ssl: true);

    expect($config)
        ->toContain("{$siteAddress} {\n\troot *")
        ->toContain($redirectBlock);
})->with([
    'apex to www' => [WwwRedirect::ToWww, 'www.myapp.com', "myapp.com {\n\tredir https://www.myapp.com{uri} permanent\n}"],
    'www to apex' => [WwwRedirect::FromWww, 'myapp.com', "www.myapp.com {\n\tredir https://myapp.com{uri} permanent\n}"],
]);
