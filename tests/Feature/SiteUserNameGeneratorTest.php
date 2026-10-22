<?php

use App\Models\Server;
use App\Models\Site;
use App\Services\SiteUserNameGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->server = Server::factory()->create();
    $this->generator = app(SiteUserNameGenerator::class);
});

it('derives the user name from the domain', function (string $domain, string $expected) {
    expect($this->generator->generate($this->server, $domain))->toBe($expected);
})->with([
    'plain domain' => ['example.com', 'example_com'],
    'subdomain' => ['app.example.co.uk', 'app_example_co_uk'],
    'uppercase and hyphens' => ['My-Shop.Example.com', 'my_shop_example_com'],
    'leading digit' => ['123shop.com', 's_123shop_com'],
]);

it('keeps names to 32 characters', function () {
    $name = $this->generator->generate($this->server, 'a-very-long-subdomain-name.for-a-very-long-domain.example.com');

    expect($name)->toBe('a_very_long_subdomain_name_for_a')
        ->and(strlen($name))->toBeLessThanOrEqual(32);
});

it('adds a numeric suffix when the name is already used on the server', function () {
    Site::factory()->forServer($this->server)->isolated('example_com')->create();
    Site::factory()->forServer($this->server)->isolated('example_com_2')->create();

    expect($this->generator->generate($this->server, 'example.com'))->toBe('example_com_3');
});

it('keeps suffixed long names within 32 characters', function () {
    $domain = 'a-very-long-subdomain-name.for-a-very-long-domain.example.com';
    Site::factory()->forServer($this->server)->isolated('a_very_long_subdomain_name_for_a')->create();

    $name = $this->generator->generate($this->server, $domain);

    expect($name)->toBe('a_very_long_subdomain_name_for_2')
        ->and(strlen($name))->toBeLessThanOrEqual(32);
});

it('only treats names on the same server as taken', function () {
    Site::factory()->forServer(Server::factory()->create())->isolated('example_com')->create();

    expect($this->generator->generate($this->server, 'example.com'))->toBe('example_com');
});

it('never hands out reserved account names', function (string $domain, string $expected) {
    expect($this->generator->generate($this->server, $domain))->toBe($expected);
})->with([
    'root' => ['root', 'root_2'],
    'server user' => ['artisan', 'artisan_2'],
    'web user' => ['www-data', 'www_data_2'],
    'mysql' => ['mysql', 'mysql_2'],
    'postgres' => ['postgres', 'postgres_2'],
    'redis' => ['redis', 'redis_2'],
    'nobody' => ['nobody', 'nobody_2'],
    'ubuntu' => ['ubuntu', 'ubuntu_2'],
]);

it('produces names Linux accepts', function (string $domain) {
    expect($this->generator->generate($this->server, $domain))->toMatch('/^[a-z][a-z0-9_]{0,31}$/');
})->with(['example.com', '9.example.com', '-.example.com', 'xn--bcher-kva.example', '_dmarc.example.com']);
