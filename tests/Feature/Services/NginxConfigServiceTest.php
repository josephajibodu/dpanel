<?php

use App\Enums\ProjectType;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Services\Nginx\NginxConfigService;

describe('NginxConfigService', function () {
    it('uses single hostname in server_name per domain file', function () {
        $server = Server::factory()->create([
            'ip_address' => '203.0.113.42',
        ]);

        $site = Site::factory()->create([
            'server_id' => $server->id,
            'domain' => 'myapp.com',
        ]);

        $domain = $site->domains()->where('is_primary', true)->firstOrFail();

        $service = new NginxConfigService;
        $config = $service->generateForSiteDomain($site, $domain);

        expect($config)
            ->toContain('server_name myapp.com')
            ->not->toContain('nip.io');
    });

    it('works when server has no IP address', function () {
        $server = Server::factory()->create([
            'ip_address' => null,
        ]);

        $site = Site::factory()->create([
            'server_id' => $server->id,
            'domain' => 'myapp.com',
        ]);

        $domain = $site->domains()->where('is_primary', true)->firstOrFail();

        $service = new NginxConfigService;
        $config = $service->generateForSiteDomain($site, $domain);

        expect($config)
            ->toContain('server_name myapp.com')
            ->not->toContain('nip.io');
    });

    it('uses hostname in SSL configuration', function () {
        $server = Server::factory()->create([
            'ip_address' => '192.168.1.100',
        ]);

        $site = Site::factory()->create([
            'server_id' => $server->id,
            'domain' => 'example.com',
        ]);

        $domain = $site->domains()->where('is_primary', true)->firstOrFail();

        $service = new NginxConfigService;
        $config = $service->generateWithSslForSiteDomain($site, $domain);

        expect($config)
            ->toContain('server_name example.com')
            ->not->toContain('nip.io');
    });

    it('does not merge aliases into one file', function () {
        $server = Server::factory()->create([
            'ip_address' => '10.0.0.1',
        ]);

        $site = Site::factory()->create([
            'server_id' => $server->id,
            'domain' => 'mysite.com',
            'aliases' => ['www.mysite.com', 'app.mysite.com'],
        ]);

        $primary = $site->domains()->where('hostname', 'mysite.com')->firstOrFail();
        $www = $site->domains()->where('hostname', 'www.mysite.com')->firstOrFail();

        $service = new NginxConfigService;
        $configPrimary = $service->generateForSiteDomain($site, $primary);
        $configWww = $service->generateForSiteDomain($site, $www);

        expect($configPrimary)->toContain('server_name mysite.com');
        expect($configWww)->toContain('server_name www.mysite.com');
        expect($configPrimary)->not->toContain('www.mysite.com');
    });

    it('handles subdomain domains correctly', function () {
        $server = Server::factory()->create([
            'ip_address' => '172.16.0.1',
        ]);

        $site = Site::factory()->create([
            'server_id' => $server->id,
            'domain' => 'app.example.com',
        ]);

        $domain = $site->domains()->where('is_primary', true)->firstOrFail();

        $service = new NginxConfigService;
        $config = $service->generateForSiteDomain($site, $domain);

        expect($config)
            ->toContain('server_name app.example.com')
            ->not->toContain('nip.io');
    });

    it('handles free domain subdomains', function () {
        $server = Server::factory()->create([
            'ip_address' => '192.168.1.50',
        ]);

        $site = Site::factory()->create([
            'server_id' => $server->id,
            'domain' => 'my-site.flitops.xyz',
        ]);

        $domain = $site->domains()->where('is_primary', true)->firstOrFail();

        $service = new NginxConfigService;
        $config = $service->generateForSiteDomain($site, $domain);

        expect($config)
            ->toContain('server_name my-site.flitops.xyz');
    });

    it('uses hostname in both HTTP and HTTPS server blocks for SSL config', function () {
        $server = Server::factory()->create([
            'ip_address' => '203.0.113.10',
        ]);

        $site = Site::factory()->create([
            'server_id' => $server->id,
            'domain' => 'test.com',
        ]);

        $domain = $site->domains()->where('is_primary', true)->firstOrFail();

        $service = new NginxConfigService;
        $config = $service->generateWithSslForSiteDomain($site, $domain);

        $httpBlock = substr($config, 0, strpos($config, '# HTTPS server'));
        $httpsBlock = substr($config, strpos($config, '# HTTPS server'));

        expect($httpBlock)
            ->toContain('server_name test.com');

        expect($httpsBlock)
            ->toContain('server_name test.com');
    });

    describe('project type configurations', function () {
        it('generates Laravel config with framework routing', function () {
            $server = Server::factory()->create(['ip_address' => '192.168.1.1']);

            $site = Site::factory()->create([
                'server_id' => $server->id,
                'domain' => 'laravel.test',
                'project_type' => ProjectType::Laravel,
                'directory' => '/public',
            ]);

            $domain = $site->domains()->where('is_primary', true)->firstOrFail();

            $service = new NginxConfigService;
            $config = $service->generateForSiteDomain($site, $domain);

            expect($config)
                ->toContain('try_files $uri $uri/ /index.php?$query_string')
                ->toContain('error_page 404 /index.php')
                ->toContain('fastcgi_pass unix:/var/run/php/php')
                ->toContain('index index.php index.html index.htm');
        });

        it('generates Symfony config with framework routing', function () {
            $server = Server::factory()->create(['ip_address' => '192.168.1.1']);

            $site = Site::factory()->create([
                'server_id' => $server->id,
                'domain' => 'symfony.test',
                'project_type' => ProjectType::Symfony,
                'directory' => '/public',
            ]);

            $domain = $site->domains()->where('is_primary', true)->firstOrFail();

            $service = new NginxConfigService;
            $config = $service->generateForSiteDomain($site, $domain);

            expect($config)
                ->toContain('try_files $uri $uri/ /index.php?$query_string')
                ->toContain('error_page 404 /index.php')
                ->toContain('fastcgi_pass unix:/var/run/php/php');
        });

        it('generates PHP generic config without framework routing', function () {
            $server = Server::factory()->create(['ip_address' => '192.168.1.1']);

            $site = Site::factory()->create([
                'server_id' => $server->id,
                'domain' => 'php.test',
                'project_type' => ProjectType::PhpGeneric,
                'directory' => '/',
            ]);

            $domain = $site->domains()->where('is_primary', true)->firstOrFail();

            $service = new NginxConfigService;
            $config = $service->generateForSiteDomain($site, $domain);

            expect($config)
                ->toContain('try_files $uri $uri/ /index.php /index.html =404')
                ->toContain('try_files $uri =404')
                ->toContain('fastcgi_pass unix:/var/run/php/php')
                ->not->toContain('error_page 404 /index.php')
                ->not->toContain('/index.php?$query_string');
        });

        it('generates static HTML config without PHP-FPM', function () {
            $server = Server::factory()->create(['ip_address' => '192.168.1.1']);

            $site = Site::factory()->create([
                'server_id' => $server->id,
                'domain' => 'static.test',
                'project_type' => ProjectType::StaticHtml,
                'directory' => '/',
            ]);

            $domain = $site->domains()->where('is_primary', true)->firstOrFail();

            $service = new NginxConfigService;
            $config = $service->generateForSiteDomain($site, $domain);

            expect($config)
                ->toContain('index index.html index.htm')
                ->toContain('try_files $uri $uri/ =404')
                ->not->toContain('fastcgi_pass')
                ->not->toContain('index.php')
                ->not->toContain('\.php$');
        });

        it('generates WordPress config with WordPress-specific rules', function () {
            $server = Server::factory()->create(['ip_address' => '192.168.1.1']);

            $site = Site::factory()->create([
                'server_id' => $server->id,
                'domain' => 'wordpress.test',
                'project_type' => ProjectType::WordPress,
                'directory' => '/',
            ]);

            $domain = $site->domains()->where('is_primary', true)->firstOrFail();

            $service = new NginxConfigService;
            $config = $service->generateForSiteDomain($site, $domain);

            expect($config)
                ->toContain('try_files $uri $uri/ /index.php?$args')
                ->toContain('location ~ ^/wp-admin/')
                ->toContain('location ~ /wp-config.php')
                ->toContain('deny all')
                ->toContain('fastcgi_pass unix:/var/run/php/php');
        });
    });

    it('includes www redirect preamble when from_www', function () {
        $server = Server::factory()->create(['ip_address' => '192.168.1.1']);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'domain' => 'apex.com',
        ]);
        $d = SiteDomain::query()->where('site_id', $site->id)->where('hostname', 'apex.com')->firstOrFail();
        $d->update(['www_redirect' => \App\Enums\WwwRedirect::FromWww]);

        $service = new NginxConfigService;
        $config = $service->generateForSiteDomain($site, $d->fresh());

        expect($config)->toContain('server_name www.apex.com');
        expect($config)->toContain('return 301 http://apex.com');
    });
});
