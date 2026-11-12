<?php

use App\Models\Server;
use App\Models\Site;
use App\Models\SourceControlAccount;
use App\Services\SourceControl\BitbucketClient;
use App\Services\SourceControl\GitlabClient;
use App\Services\SourceControlService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

const SERVER_KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIServerKey flitops@server';

// ---------------------------------------------------------------------------
// GitLab
// ---------------------------------------------------------------------------

it('lists every page of gitlab projects, nested groups included', function () {
    Http::fake([
        'gitlab.com/api/v4/projects?*&page=1' => Http::response([
            ['id' => 1, 'name' => 'App', 'path_with_namespace' => 'acme/web/app', 'ssh_url_to_repo' => 'git@gitlab.com:acme/web/app.git', 'web_url' => 'https://gitlab.com/acme/web/app', 'default_branch' => 'main', 'visibility' => 'private'],
        ], 200, ['X-Next-Page' => '2']),
        'gitlab.com/api/v4/projects?*&page=2' => Http::response([
            ['id' => 2, 'name' => 'Docs', 'path_with_namespace' => 'acme/docs', 'ssh_url_to_repo' => 'git@gitlab.com:acme/docs.git', 'web_url' => 'https://gitlab.com/acme/docs', 'default_branch' => 'trunk', 'visibility' => 'public'],
        ], 200, ['X-Next-Page' => '']),
    ]);

    $repositories = app(SourceControlService::class)->listRepositories(SourceControlAccount::factory()->gitlab()->create());

    expect($repositories->pluck('full_name')->all())->toBe(['acme/web/app', 'acme/docs'])
        ->and($repositories->first()['private'])->toBeTrue()
        ->and($repositories->last()['default_branch'])->toBe('trunk');
});

it('lists a gitlab project\'s branches by its encoded path', function () {
    Http::fake(['gitlab.com/api/v4/projects/acme%2Fweb%2Fapp/repository/branches*' => Http::response([
        ['name' => 'main', 'protected' => true],
        ['name' => 'feature', 'protected' => false],
    ])]);

    $branches = app(SourceControlService::class)->listBranches(SourceControlAccount::factory()->gitlab()->create(), 'acme/web/app');

    expect($branches->all())->toBe([['name' => 'main', 'protected' => true], ['name' => 'feature', 'protected' => false]]);
});

it('reads the top-level files and one file of a gitlab project on a branch', function () {
    Http::fake([
        'gitlab.com/api/v4/projects/acme%2Fweb%2Fapp/repository/tree?ref=release%2F2.0*' => Http::response([
            ['name' => 'src', 'type' => 'tree'],
            ['name' => 'package.json', 'type' => 'blob'],
        ]),
        'gitlab.com/api/v4/projects/acme%2Fweb%2Fapp/repository/files/docs%2Fpackage.json/raw?ref=release%2F2.0' => Http::response('{"name":"app"}'),
    ]);
    $account = SourceControlAccount::factory()->gitlab()->create();

    $service = app(SourceControlService::class);

    expect($service->listRootEntries($account, 'acme/web/app', 'release/2.0'))->toBe(['src', 'package.json'])
        ->and($service->readFile($account, 'acme/web/app', 'release/2.0', 'docs/package.json'))->toBe('{"name":"app"}');
});

it('reads a missing gitlab file as null', function () {
    Http::fake(['gitlab.com/api/v4/projects/acme%2Fapp/repository/files/package.json/raw*' => Http::response(['message' => '404 File Not Found'], 404)]);

    $contents = app(SourceControlService::class)->readFile(SourceControlAccount::factory()->gitlab()->create(), 'acme/app', 'main', 'package.json');

    expect($contents)->toBeNull();
});

it('adds a server key to a gitlab account only when it is not there yet', function () {
    Http::fake([
        'gitlab.com/api/v4/user/keys?*' => Http::response([['id' => 7, 'key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOther']]),
        'gitlab.com/api/v4/user/keys' => Http::response(['id' => 8], 201),
    ]);
    $server = Server::factory()->create(['local_public_key' => SERVER_KEY]);

    app(SourceControlService::class)->ensureAccountSshKey($server, SourceControlAccount::factory()->gitlab()->create());

    Http::assertSent(fn (Request $request) => $request->method() === 'POST' && $request['key'] === SERVER_KEY);
});

it('skips adding a server key gitlab already has, whatever its comment', function () {
    Http::fake(['gitlab.com/api/v4/user/keys?*' => Http::response([['id' => 7, 'key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIServerKey']])]);
    $server = Server::factory()->create(['local_public_key' => SERVER_KEY]);

    app(GitlabClient::class)->addSshKey(SourceControlAccount::factory()->gitlab()->create(), SERVER_KEY, 'FlitOps (web-1)');

    Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
});

it('adds a gitlab push webhook filtered to the site branch, with the secret as its token', function () {
    Http::fake(['gitlab.com/api/v4/projects/acme%2Fapp/hooks' => Http::response(['id' => 99], 201)]);
    $site = Site::factory()->forSourceControlAccount(SourceControlAccount::factory()->gitlab()->create())
        ->create(['repository' => 'acme/app', 'repository_provider' => 'gitlab', 'branch' => 'production']);

    expect(app(SourceControlService::class)->createDeployWebhook($site))->toBe('99');

    Http::assertSent(fn (Request $request) => $request['url'] === route('deployment-webhook.handle', $site->webhook_secret)
        && $request['token'] === $site->webhook_secret
        && $request['push_events'] === true
        && $request['push_events_branch_filter'] === 'production');
});

it('explains when gitlab refuses a webhook', function () {
    Http::fake(['gitlab.com/*' => Http::response(['message' => '403 Forbidden'], 403)]);
    $site = Site::factory()->forSourceControlAccount(SourceControlAccount::factory()->gitlab()->create())
        ->create(['repository' => 'acme/app', 'repository_provider' => 'gitlab']);

    app(SourceControlService::class)->createDeployWebhook($site);
})->throws(RuntimeException::class, 'Maintainer role');

it('refreshes an expired gitlab token before calling the api', function () {
    Http::fake([
        'gitlab.com/oauth/token' => Http::response(['access_token' => 'fresh-token', 'refresh_token' => 'new-refresh', 'expires_in' => 7200]),
        'gitlab.com/api/v4/*' => Http::response([]),
    ]);
    $account = SourceControlAccount::factory()->gitlab()->create([
        'token' => 'stale-token',
        'refresh_token' => 'old-refresh',
        'token_expires_at' => now()->subMinute(),
    ]);

    app(GitlabClient::class)->listRepositories($account);

    expect($account->fresh())
        ->token->toBe('fresh-token')
        ->refresh_token->toBe('new-refresh')
        ->token_expires_at->isFuture()->toBeTrue();
    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/oauth/token') && $request['refresh_token'] === 'old-refresh');
    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/api/v4/') && $request->hasHeader('Authorization', 'Bearer fresh-token'));
});

it('asks for a reconnect when a gitlab token can no longer be refreshed', function () {
    Http::fake(['gitlab.com/oauth/token' => Http::response(['error' => 'invalid_grant'], 400)]);
    $account = SourceControlAccount::factory()->gitlab()->create(['refresh_token' => 'revoked', 'token_expires_at' => now()->subMinute()]);

    app(GitlabClient::class)->listRepositories($account);
})->throws(RuntimeException::class, 'Reconnect the account');

// ---------------------------------------------------------------------------
// Bitbucket
// ---------------------------------------------------------------------------

it('lists every page of bitbucket repositories by following next links', function () {
    Http::fake([
        'api.bitbucket.org/2.0/repositories?*' => Http::response([
            'values' => [['uuid' => '{r1}', 'name' => 'app', 'full_name' => 'acme/app', 'is_private' => true, 'mainbranch' => ['name' => 'main'], 'links' => ['html' => ['href' => 'https://bitbucket.org/acme/app'], 'clone' => [['name' => 'ssh', 'href' => 'git@bitbucket.org:acme/app.git']]]]],
            'next' => 'https://api.bitbucket.org/2.0/repositories/page-two?page=2',
        ]),
        'api.bitbucket.org/2.0/repositories/page-two*' => Http::response([
            'values' => [['uuid' => '{r2}', 'name' => 'site', 'full_name' => 'acme/site', 'is_private' => false, 'links' => []]],
        ]),
    ]);

    $repositories = app(SourceControlService::class)->listRepositories(SourceControlAccount::factory()->bitbucket()->create());

    expect($repositories->pluck('full_name')->all())->toBe(['acme/app', 'acme/site'])
        ->and($repositories->first()['ssh_url'])->toBe('git@bitbucket.org:acme/app.git')
        ->and($repositories->last()['ssh_url'])->toBe('git@bitbucket.org:acme/site.git');
});

it('reads the top-level files and one file of a bitbucket repository on a branch', function () {
    Http::fake([
        'api.bitbucket.org/2.0/repositories/acme/app/src/main/?*' => Http::response(['values' => [
            ['path' => 'src', 'type' => 'commit_directory'],
            ['path' => 'package.json', 'type' => 'commit_file'],
        ]]),
        'api.bitbucket.org/2.0/repositories/acme/app/src/main/package.json' => Http::response('{"name":"app"}'),
    ]);
    $account = SourceControlAccount::factory()->bitbucket()->create();

    $service = app(SourceControlService::class);

    expect($service->listRootEntries($account, 'acme/app', 'main'))->toBe(['src', 'package.json'])
        ->and($service->readFile($account, 'acme/app', 'main', 'package.json'))->toBe('{"name":"app"}');
});

it('reads a bitbucket branch with a slash in its name by its latest commit', function () {
    Http::fake([
        'api.bitbucket.org/2.0/repositories/acme/app/refs/branches/release/2.0' => Http::response(['name' => 'release/2.0', 'target' => ['hash' => 'abc123']]),
        'api.bitbucket.org/2.0/repositories/acme/app/src/abc123/package.json' => Http::response('{"name":"app"}'),
    ]);

    $contents = app(SourceControlService::class)->readFile(SourceControlAccount::factory()->bitbucket()->create(), 'acme/app', 'release/2.0', 'package.json');

    expect($contents)->toBe('{"name":"app"}');
});

it('reads nothing from a bitbucket branch that does not exist', function () {
    Http::fake(['api.bitbucket.org/2.0/repositories/acme/app/refs/branches/gone/away' => Http::response(['type' => 'error'], 404)]);
    $account = SourceControlAccount::factory()->bitbucket()->create();

    $service = app(SourceControlService::class);

    expect($service->listRootEntries($account, 'acme/app', 'gone/away'))->toBe([])
        ->and($service->readFile($account, 'acme/app', 'gone/away', 'package.json'))->toBeNull();
});

it('does not follow a bitbucket next link to another host', function () {
    Http::fake([
        'api.bitbucket.org/*' => Http::response(['values' => [], 'next' => 'https://evil.example.com/steal']),
        'evil.example.com/*' => Http::response(['values' => []]),
    ]);

    app(BitbucketClient::class)->listRepositories(SourceControlAccount::factory()->bitbucket()->create());

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'evil.example.com'));
});

it('adds a server key to the bitbucket account by its uuid', function () {
    Http::fake(['api.bitbucket.org/2.0/users/*/ssh-keys*' => Http::sequence()
        ->push(['values' => []])
        ->push(['uuid' => '{k1}'], 201)]);
    $account = SourceControlAccount::factory()->bitbucket()->create(['provider_user_id' => '{abc}']);

    app(BitbucketClient::class)->addSshKey($account, SERVER_KEY, 'FlitOps (web-1)');

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && str_contains($request->url(), '/users/%7Babc%7D/ssh-keys')
        && $request['key'] === SERVER_KEY
        && $request['label'] === 'FlitOps (web-1)');
});

it('adds a signed bitbucket push webhook and removes it again', function () {
    Http::fake([
        'api.bitbucket.org/2.0/repositories/acme/app/hooks' => Http::response(['uuid' => '{h1}'], 201),
        'api.bitbucket.org/2.0/repositories/acme/app/hooks/*' => Http::response(null, 204),
    ]);
    $site = Site::factory()->forSourceControlAccount(SourceControlAccount::factory()->bitbucket()->create())
        ->create(['repository' => 'acme/app', 'repository_provider' => 'bitbucket']);

    $webhookId = app(SourceControlService::class)->createDeployWebhook($site);
    $site->update(['deploy_webhook_id' => $webhookId]);
    app(SourceControlService::class)->deleteDeployWebhook($site);

    expect($webhookId)->toBe('{h1}');
    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request['secret'] === $site->webhook_secret
        && $request['events'] === ['repo:push']);
    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/hooks/%7Bh1%7D'));
});

it('refreshes an expired bitbucket token with the consumer credentials', function () {
    config(['services.bitbucket.client_id' => 'consumer-key', 'services.bitbucket.client_secret' => 'consumer-secret']);
    Http::fake([
        'bitbucket.org/site/oauth2/access_token' => Http::response(['access_token' => 'fresh-token', 'expires_in' => 7200]),
        'api.bitbucket.org/*' => Http::response(['values' => []]),
    ]);
    $account = SourceControlAccount::factory()->bitbucket()->create(['refresh_token' => 'old-refresh', 'token_expires_at' => now()->subMinute()]);

    app(BitbucketClient::class)->listRepositories($account);

    expect($account->fresh()->token)->toBe('fresh-token')
        ->and($account->fresh()->refresh_token)->toBe('old-refresh');
    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'access_token')
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('consumer-key:consumer-secret')));
});
