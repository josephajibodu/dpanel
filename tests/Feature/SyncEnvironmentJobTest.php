<?php

use App\Enums\ProjectType;
use App\Jobs\SyncEnvironmentJob;
use App\Models\Server;
use App\Models\Site;
use App\Services\Ssh\SshConnection;
use App\Services\Ssh\SshService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function siteWithEnvironment(ProjectType $projectType): Site
{
    return Site::factory()->forServer(Server::factory()->create())->create([
        'domain' => 'example.com',
        'project_type' => $projectType,
        'php_version' => '8.4',
        'env_content' => "APP_KEY=secret\n",
    ]);
}

/**
 * Runs the job against a fake connection and returns every command it ran.
 *
 * @return list<string>
 */
function commandsRunBySyncEnvironment(Site $site, bool $restartQueue): array
{
    $commands = [];

    $connection = Mockery::mock(SshConnection::class);
    $connection->shouldReceive('exec')->andReturnUsing(function (string $command) use (&$commands) {
        $commands[] = $command;

        return '';
    });
    $connection->shouldReceive('upload', 'disconnect')->andReturnNull();

    $sshService = Mockery::mock(SshService::class);
    $sshService->shouldReceive('connect')->andReturn($connection);

    (new SyncEnvironmentJob($site, restartQueue: $restartQueue))->handle($sshService);

    return $commands;
}

it('restarts queue workers, Horizon and Reverb from the live release when asked', function () {
    $site = siteWithEnvironment(ProjectType::Laravel);

    $commands = commandsRunBySyncEnvironment($site, restartQueue: true);

    $restart = collect($commands)->first(fn (string $command) => str_contains($command, 'queue:restart'));

    expect($restart)->toStartWith("set -e\ncd /home/artisan/example.com/current\nPHP=php8.4\n")
        ->toContain('$PHP artisan queue:restart')
        ->toContain('$PHP artisan horizon:terminate')
        ->toContain('$PHP artisan reverb:restart');
});

it('does not restart anything when not asked', function () {
    $site = siteWithEnvironment(ProjectType::Laravel);

    $commands = commandsRunBySyncEnvironment($site, restartQueue: false);

    expect(implode("\n", $commands))->not->toContain('queue:restart')
        ->not->toContain('horizon:terminate')
        ->not->toContain('reverb:restart');
});

it('does not run artisan restarts for a non-laravel site', function () {
    $site = siteWithEnvironment(ProjectType::PhpGeneric);

    $commands = commandsRunBySyncEnvironment($site, restartQueue: true);

    expect(implode("\n", $commands))->not->toContain('queue:restart')
        ->not->toContain('horizon:terminate')
        ->not->toContain('reverb:restart');
});
