<?php

use App\Actions\Sites\CleanupSiteExternalResourcesAction;
use App\Models\Server;
use App\Models\Site;
use App\Models\SourceControlAccount;
use App\Services\Cloudflare\CloudflareDnsService;
use App\Services\SourceControlService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('deletes each cloudflare DNS record passed in', function () {
    $cloudflare = Mockery::mock(CloudflareDnsService::class);
    $cloudflare->shouldReceive('deleteRecord')->once()->with('cf-1');
    $cloudflare->shouldReceive('deleteRecord')->once()->with('cf-2');

    $sourceControl = Mockery::mock(SourceControlService::class);
    $sourceControl->shouldNotReceive('deleteAccountSshKeyIfUnused');

    $action = new CleanupSiteExternalResourcesAction($cloudflare, $sourceControl);

    $action->execute(
        cloudflareDnsRecordIds: ['cf-1', 'cf-2'],
        domain: 'example.test',
    );
});

it('continues on a cloudflare error and still processes remaining records', function () {
    $cloudflare = Mockery::mock(CloudflareDnsService::class);
    $cloudflare->shouldReceive('deleteRecord')
        ->once()->with('cf-broken')->andThrow(new RuntimeException('cloudflare down'));
    $cloudflare->shouldReceive('deleteRecord')
        ->once()->with('cf-ok');

    $sourceControl = Mockery::mock(SourceControlService::class);

    $action = new CleanupSiteExternalResourcesAction($cloudflare, $sourceControl);

    $action->execute(
        cloudflareDnsRecordIds: ['cf-broken', 'cf-ok'],
    );
});

it('calls GitHub SSH key cleanup when server, account, and flag are provided', function () {
    $cloudflare = Mockery::mock(CloudflareDnsService::class);

    $server = Server::factory()->create();
    $account = SourceControlAccount::factory()->github()->create();

    $sourceControl = Mockery::mock(SourceControlService::class);
    $sourceControl->shouldReceive('deleteAccountSshKeyIfUnused')
        ->once()
        ->withArgs(fn ($s, $a) => $s->id === $server->id && $a->id === $account->id);

    $action = new CleanupSiteExternalResourcesAction($cloudflare, $sourceControl);

    $action->execute(
        cloudflareDnsRecordIds: [],
        server: $server,
        sourceControlAccount: $account,
    );
});

it('skips GitHub SSH key cleanup when the cleanupGithubSshKey flag is false', function () {
    $cloudflare = Mockery::mock(CloudflareDnsService::class);

    $server = Server::factory()->create();
    $account = SourceControlAccount::factory()->github()->create();

    $sourceControl = Mockery::mock(SourceControlService::class);
    $sourceControl->shouldNotReceive('deleteAccountSshKeyIfUnused');

    $action = new CleanupSiteExternalResourcesAction($cloudflare, $sourceControl);

    $action->execute(
        cloudflareDnsRecordIds: [],
        server: $server,
        sourceControlAccount: $account,
        cleanupGithubSshKey: false,
    );
});

it('skips GitHub SSH key cleanup when server is null', function () {
    $cloudflare = Mockery::mock(CloudflareDnsService::class);

    $account = SourceControlAccount::factory()->github()->create();

    $sourceControl = Mockery::mock(SourceControlService::class);
    $sourceControl->shouldNotReceive('deleteAccountSshKeyIfUnused');

    $action = new CleanupSiteExternalResourcesAction($cloudflare, $sourceControl);

    $action->execute(
        cloudflareDnsRecordIds: [],
        sourceControlAccount: $account,
    );
});

it('swallows GitHub SSH key cleanup errors so the caller can keep going', function () {
    $cloudflare = Mockery::mock(CloudflareDnsService::class);

    $server = Server::factory()->create();
    $account = SourceControlAccount::factory()->github()->create();

    $sourceControl = Mockery::mock(SourceControlService::class);
    $sourceControl->shouldReceive('deleteAccountSshKeyIfUnused')
        ->once()
        ->andThrow(new RuntimeException('github rejected the request'));

    $action = new CleanupSiteExternalResourcesAction($cloudflare, $sourceControl);

    // Expectation: no exception bubbles up.
    $action->execute(
        cloudflareDnsRecordIds: [],
        server: $server,
        sourceControlAccount: $account,
    );
});

it('removes the site\'s deploy webhook, and carries on if that fails', function () {
    $cloudflare = Mockery::mock(CloudflareDnsService::class);
    $cloudflare->shouldReceive('deleteRecord')->once()->with('cf-1');

    $site = Site::factory()->create(['deploy_webhook_id' => '4242']);

    $sourceControl = Mockery::mock(SourceControlService::class);
    $sourceControl->shouldReceive('deleteDeployWebhook')->once()->with($site)->andThrow(new RuntimeException('GitHub down'));

    (new CleanupSiteExternalResourcesAction($cloudflare, $sourceControl))->execute(
        cloudflareDnsRecordIds: ['cf-1'],
        site: $site,
    );
});
