<?php

use App\Enums\ProjectType;
use App\Models\Server;
use App\Models\Site;
use App\Services\NginxConfigService;

describe('NginxConfigService', function () {
    it('includes nip.io domain in server_name when server has IP address', function () {
        $server = Server::factory()->create([
            'ip_address' => '203.0.113.42',
        ]);

        $site = Site::factory()->create([
            'server_id' => $server->id,
            'domain' => 'myapp.com',
        ]);

        $service = new NginxConfigService;
        $config = $service->generate($site);

        expect($config)
            ->toContain('server_name myapp.com myapp-203.0.113.42.nip.io')
            ->toContain('myapp.com')
            ->toContain('myapp-203.0.113.42.nip.io');
    });

    it('does not include nip.io domain when server has no IP address', function () {
        $server = Server::factory()->create([
            'ip_address' => null,
        ]);

        $site = Site::factory()->create([
            'server_id' => $server->id,
            'domain' => 'myapp.com',
        ]);

        $service = new NginxConfigService;
        $config = $service->generate($site);

        expect($config)
            ->toContain('server_name myapp.com')
            ->not->toContain('nip.io');
    });

    it('includes nip.io domain in SSL configuration', function () {
        $server = Server::factory()->create([
            'ip_address' => '192.168.1.100',
        ]);

        $site = Site::factory()->create([
            'server_id' => $server->id,
            'domain' => 'example.com',
        ]);

        $service = new NginxConfigService;
        $config = $service->generateWithSsl($site);

        expect($config)
            ->toContain('server_name example.com example-192.168.1.100.nip.io')
            ->toContain('example-192.168.1.100.nip.io');
    });

    it('includes aliases along with nip.io domain', function () {
        $server = Server::factory()->create([
            'ip_address' => '10.0.0.1',
        ]);

        $site = Site::factory()->create([
            'server_id' => $server->id,
            'domain' => 'mysite.com',
            'aliases' => ['www.mysite.com', 'app.mysite.com'],
        ]);

        $service = new NginxConfigService;
        $config = $service->generate($site);

        expect($config)
            ->toContain('mysite.com')
            ->toContain('www.mysite.com')
            ->toContain('app.mysite.com')
            ->toContain('mysite-10.0.0.1.nip.io');
    });

    it('generates correct nip.io domain format for subdomain domains', function () {
        $server = Server::factory()->create([
            'ip_address' => '172.16.0.1',
        ]);

        $site = Site::factory()->create([
            'server_id' => $server->id,
            'domain' => 'app.example.com',
        ]);

        $service = new NginxConfigService;
        $config = $service->generate($site);

        // Should extract "app" from "app.example.com"
        expect($config)
            ->toContain('app-172.16.0.1.nip.io')
            ->toContain('app.example.com');
    });

    it('handles domains with special characters in nip.io generation', function () {
        $server = Server::factory()->create([
            'ip_address' => '192.168.1.50',
        ]);

        $site = Site::factory()->create([
            'server_id' => $server->id,
            'domain' => 'my-site.com',
        ]);

        $service = new NginxConfigService;
        $config = $service->generate($site);

        // Should sanitize and use "my-site" or "mysite"
        expect($config)
            ->toContain('192.168.1.50.nip.io')
            ->toContain('my-site.com');
    });

    it('includes nip.io domain in both HTTP and HTTPS server blocks for SSL config', function () {
        $server = Server::factory()->create([
            'ip_address' => '203.0.113.10',
        ]);

        $site = Site::factory()->create([
            'server_id' => $server->id,
            'domain' => 'test.com',
        ]);

        $service = new NginxConfigService;
        $config = $service->generateWithSsl($site);

        // Should appear in both the redirect block and the HTTPS block
        $httpBlock = substr($config, 0, strpos($config, '# HTTPS server'));
        $httpsBlock = substr($config, strpos($config, '# HTTPS server'));

        expect($httpBlock)
            ->toContain('test-203.0.113.10.nip.io');

        expect($httpsBlock)
            ->toContain('test-203.0.113.10.nip.io');
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

            $service = new NginxConfigService;
            $config = $service->generate($site);

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

            $service = new NginxConfigService;
            $config = $service->generate($site);

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

            $service = new NginxConfigService;
            $config = $service->generate($site);

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

            $service = new NginxConfigService;
            $config = $service->generate($site);

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

            $service = new NginxConfigService;
            $config = $service->generate($site);

            expect($config)
                ->toContain('try_files $uri $uri/ /index.php?$args')
                ->toContain('location ~ ^/wp-admin/')
                ->toContain('location ~ /wp-config.php')
                ->toContain('deny all')
                ->toContain('fastcgi_pass unix:/var/run/php/php');
        });
    });
});
