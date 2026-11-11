<?php

use App\Enums\ProjectType;
use App\Enums\WwwRedirect;
use App\Models\Certificate;
use App\Models\Server;
use App\Models\Site;
use App\Services\Nginx\NginxConfigService;
use Illuminate\Support\Facades\Config;

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

    it('sends HTTP visitors to HTTPS from the same file as the HTTPS site', function () {
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

        expect($config)
            ->toContain('listen 443 ssl http2')
            ->toContain('server_name test.com')
            ->toContain('return 301 https://$host$request_uri;');
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
                ->toContain("fastcgi_pass unix:/run/php/php{$site->php_version}-fpm-".config('server.user').'.sock;')
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
                ->toContain("fastcgi_pass unix:/run/php/php{$site->php_version}-fpm-".config('server.user').'.sock;');
        });

        it('generates PHP generic config that hands unmatched URLs to index.php', function () {
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
                ->toContain('try_files $uri $uri/ /index.php?$query_string;')
                ->toContain('try_files $uri =404')
                ->toContain("fastcgi_pass unix:/run/php/php{$site->php_version}-fpm-".config('server.user').'.sock;')
                ->not->toContain('error_page 404 /index.php');
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
                ->toContain("fastcgi_pass unix:/run/php/php{$site->php_version}-fpm-".config('server.user').'.sock;');
        });
    });

    it('raises FastCGI buffers so large response headers do not 502', function (ProjectType $projectType) {
        $site = Site::factory()->create([
            'domain' => 'buffers.test',
            'project_type' => $projectType,
        ]);

        $domain = $site->domains()->where('is_primary', true)->firstOrFail();

        $config = (new NginxConfigService)->generateForSiteDomain($site, $domain);

        expect($config)
            ->toContain('fastcgi_buffers 16 16k;')
            ->toContain('fastcgi_buffer_size 32k;');
    })->with([ProjectType::Laravel, ProjectType::PhpGeneric, ProjectType::WordPress]);

    it('never lists a PHP file where nginx would send its source to visitors', function (ProjectType $projectType) {
        $site = Site::factory()->create([
            'domain' => 'source.test',
            'project_type' => $projectType,
        ]);

        $domain = $site->domains()->where('is_primary', true)->firstOrFail();

        $config = (new NginxConfigService)->generateForSiteDomain($site, $domain);

        preg_match_all('/try_files\s+([^;]+);/', $config, $directives);

        $servedAsPlainFiles = collect($directives[1])
            ->flatMap(fn (string $entries): array => array_slice(preg_split('/\s+/', trim($entries)), 0, -1))
            ->filter(fn (string $entry): bool => str_contains($entry, '.php'));

        expect($directives[1])->not->toBeEmpty();
        expect($servedAsPlainFiles->all())->toBeEmpty();
    })->with(ProjectType::cases());

    it('redirects www to the bare domain from the main config', function () {
        $site = Site::factory()->create(['domain' => 'apex.com']);
        $domain = $site->domains()->firstOrFail();
        $domain->update(['www_redirect' => WwwRedirect::FromWww]);

        $config = (new NginxConfigService)->generateForSiteDomain($site, $domain);

        expect($config)
            ->toContain("server_name www.apex.com;\n")
            ->toContain('return 301 http://apex.com$request_uri;')
            ->toContain("server_name apex.com;\n");
    });

    it('answers the www redirect over HTTPS with the domain\'s certificate', function () {
        $site = Site::factory()->create(['domain' => 'apex.com']);
        $domain = $site->domains()->firstOrFail();
        $domain->update(['www_redirect' => WwwRedirect::ToWww]);

        $config = (new NginxConfigService)->generateWithSslForSiteDomain($site, $domain);

        expect($config)
            ->toContain("    listen [::]:443 ssl http2;\n    ssl_certificate /etc/nginx/ssl/domains/{$site->id}/{$domain->id}/server.crt;")
            ->toContain("server_name apex.com;\n")
            ->toContain('return 301 https://www.apex.com$request_uri;')
            ->toContain("server_name www.apex.com;\n");
    });

    it('treats a country domain like example.com.ng as a bare domain', function () {
        $site = Site::factory()->create(['domain' => 'shop.com.ng']);
        $domain = $site->domains()->firstOrFail();
        $domain->update(['www_redirect' => WwwRedirect::FromWww]);

        $config = (new NginxConfigService)->generateForSiteDomain($site, $domain);

        expect($config)->toContain('return 301 http://shop.com.ng$request_uri;');
    });

    it('ignores a www redirect set on a subdomain', function (WwwRedirect $redirect) {
        $site = Site::factory()->create(['domain' => 'app.example.com']);
        $domain = $site->domains()->firstOrFail();
        $domain->update(['www_redirect' => $redirect]);

        $config = (new NginxConfigService)->generateForSiteDomain($site, $domain);

        expect($config)
            ->toContain("server_name app.example.com;\n")
            ->not->toContain('www.app.example.com');
    })->with([WwwRedirect::FromWww, WwwRedirect::ToWww]);

    it('keeps the site\'s error log and certificate checks in the main config, and includes only the user\'s two folders', function () {
        $site = Site::factory()->create(['domain' => 'myapp.com']);
        $domain = $site->domains()->firstOrFail();
        $snippets = $domain->nginxSnippetsBasePath();

        $config = (new NginxConfigService)->generateForSiteDomain($site, $domain);

        expect($config)
            ->toContain("error_log /var/log/nginx/{$site->ulid}-error.log;")
            ->toContain("location ^~ /.well-known/acme-challenge/ {\n        root {$site->acmeChallengeRoot()};")
            ->toContain("include {$snippets}/server/*.conf;")
            ->toContain("include {$snippets}/after/*.conf;")
            ->not->toContain('/before/')
            ->not->toContain('/locations/');
    });

    describe('SSL cert paths', function () {
        it('emits Forge-style ID-based cert paths in the SSL variant', function () {
            $server = Server::factory()->create(['ip_address' => '192.168.1.1']);
            $site = Site::factory()->create([
                'server_id' => $server->id,
                'domain' => 'example.com',
            ]);
            $domain = $site->domains()->where('is_primary', true)->firstOrFail();

            $config = (new NginxConfigService)->generateWithSslForSiteDomain($site, $domain);

            expect($config)
                ->toContain("ssl_certificate /etc/nginx/ssl/domains/{$site->id}/{$domain->id}/server.crt;")
                ->toContain("ssl_certificate_key /etc/nginx/ssl/domains/{$site->id}/{$domain->id}/server.key;")
                ->toContain('listen 443 ssl http2');
        });

        it('auto-picks the SSL variant for a free-domain site when a healthy wildcard certificate exists', function () {
            Config::set('server.free_domain', 'flitops.test');
            Certificate::factory()->create([
                'domain' => '*.flitops.test',
                'expires_at' => now()->addDays(80),
            ]);

            $server = Server::factory()->create(['ip_address' => '192.168.1.1']);
            $site = Site::factory()->create([
                'server_id' => $server->id,
                'domain' => 'bold-cloud.flitops.test',
            ]);
            $domain = $site->domains()->where('is_primary', true)->firstOrFail();

            $config = (new NginxConfigService)->generateForSiteDomainAuto($site, $domain);

            expect($config)
                ->toContain('listen 443 ssl http2')
                ->toContain("ssl_certificate /etc/nginx/ssl/domains/{$site->id}/{$domain->id}/server.crt;");
        });

        it('falls back to the HTTP variant for a free-domain site when the wildcard certificate is missing', function () {
            Config::set('server.free_domain', 'flitops.test');

            $server = Server::factory()->create(['ip_address' => '192.168.1.1']);
            $site = Site::factory()->create([
                'server_id' => $server->id,
                'domain' => 'bold-cloud.flitops.test',
            ]);
            $domain = $site->domains()->where('is_primary', true)->firstOrFail();

            $config = (new NginxConfigService)->generateForSiteDomainAuto($site, $domain);

            expect($config)
                ->not->toContain('listen 443')
                ->not->toContain('ssl_certificate');
        });

        it('does not auto-enable SSL for custom domains even when a wildcard cert exists', function () {
            Config::set('server.free_domain', 'flitops.test');
            Certificate::factory()->create([
                'domain' => '*.flitops.test',
                'expires_at' => now()->addDays(80),
            ]);

            $server = Server::factory()->create(['ip_address' => '192.168.1.1']);
            $site = Site::factory()->create([
                'server_id' => $server->id,
                'domain' => 'mycompany.com',
            ]);
            $domain = $site->domains()->where('is_primary', true)->firstOrFail();

            $config = (new NginxConfigService)->generateForSiteDomainAuto($site, $domain);

            expect($config)->not->toContain('listen 443');
        });
    });
});
