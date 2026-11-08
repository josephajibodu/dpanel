<?php

use App\Actions\Sites\ProvisionSiteAction;
use App\Enums\ProjectType;
use App\Enums\ServerStatus;
use App\Events\ServerSitesUpdated;
use App\Models\Server;
use App\Models\Site;
use App\Models\SourceControlAccount;
use App\Models\Team;
use App\Models\User;
use App\Services\Ssh\SshConnection;
use App\Services\Ssh\SshService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('trusts the host of the site\'s git provider when setting the site up', function (string $provider, string $host) {
    Event::fake([ServerSitesUpdated::class]);
    Queue::fake();
    Http::fake(['*' => Http::response([])]);

    $user = User::factory()->create();
    $server = Server::factory()->forTeam(Team::factory()->forUser($user)->create())->create([
        'status' => ServerStatus::Active,
        'local_public_key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIServerKey',
    ]);
    $account = SourceControlAccount::factory()->create(['user_id' => $user->id, 'provider' => $provider]);
    $site = Site::factory()->forServer($server)->forSourceControlAccount($account)->pending()->create([
        'repository' => 'acme/app',
        'repository_provider' => $provider,
        'project_type' => ProjectType::StaticHtml,
    ]);

    $commands = [];
    $connection = Mockery::mock(SshConnection::class)->makePartial();
    $connection->shouldReceive('exec')->andReturnUsing(function (string $command) use (&$commands) {
        $commands[] = $command;

        return match (true) {
            str_contains($command, 'nginx -t') => 'syntax is ok',
            str_contains($command, 'known_hosts 2>/dev/null && echo') => 'no',
            default => '',
        };
    });
    $connection->shouldReceive('disconnect');
    $sshService = Mockery::mock(SshService::class);
    $sshService->shouldReceive('connect')->andReturn($connection);
    app()->instance(SshService::class, $sshService);

    app(ProvisionSiteAction::class)->execute($site);

    expect(collect($commands)->first(fn ($command) => str_starts_with($command, 'ssh-keyscan')))
        ->toContain(" {$host} >>");
})->with([
    'github' => ['github', 'github.com'],
    'gitlab' => ['gitlab', 'gitlab.com'],
    'bitbucket' => ['bitbucket', 'bitbucket.org'],
]);
