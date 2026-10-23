<?php

use App\Actions\ServerPhp\UpdatePhpSettings;
use App\Enums\ConnectionStatus;
use App\Enums\ServerStatus;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Ssh\SshConnection;
use App\Services\Ssh\SshService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

const ORIGINAL_PHP_INI = <<<'INI'
[PHP]
; Maximum allowed size for uploaded files.
upload_max_filesize = 2M
post_max_size = 8M
max_execution_time = 30
memory_limit = 128M
display_errors = Off
INI;

beforeEach(function () {
    $user = User::factory()->create();
    $team = Team::factory()->forUser($user)->create();
    $this->server = Server::factory()->forTeam($team)->create([
        'status' => ServerStatus::Active,
        'connection_status' => ConnectionStatus::Successful,
        'php_version' => '8.3',
    ]);

    $this->uploads = [];
    $uploads = &$this->uploads;

    $this->connection = Mockery::mock(SshConnection::class);
    $this->connection->shouldReceive('exec')->andReturnUsing(
        fn (string $command) => $command === 'mktemp -d' ? '/tmp/tmp.fleetops' : ''
    );
    $this->connection->shouldReceive('sudo')->andReturnUsing(
        fn (string $command) => str_starts_with($command, 'cat ') ? ORIGINAL_PHP_INI : ''
    );
    $this->connection->shouldReceive('upload')->andReturnUsing(function (string $content) use (&$uploads) {
        $uploads[] = $content;
    });
    $this->connection->shouldReceive('disconnect');

    $sshService = Mockery::mock(SshService::class);
    $sshService->shouldReceive('connect')->andReturn($this->connection);
    $this->app->instance(SshService::class, $sshService);
});

it('changes only the requested php.ini lines', function () {
    app(UpdatePhpSettings::class)->execute($this->server, [
        'upload_max_filesize' => '64M',
        'memory_limit' => '-1',
    ]);

    expect($this->uploads)->toHaveCount(1)
        ->and($this->uploads[0])->toBe(str_replace(
            ['upload_max_filesize = 2M', 'memory_limit = 128M'],
            ['upload_max_filesize = 64M', 'memory_limit = -1'],
            ORIGINAL_PHP_INI,
        ));
});

it('writes values containing regex back-references literally', function (string $value) {
    app(UpdatePhpSettings::class)->execute($this->server, ['memory_limit' => $value]);

    expect($this->uploads[0])->toContain("memory_limit = {$value}\n");
})->with([
    'dollar reference' => '$1',
    'backslash reference' => '\\1',
    'whole-match reference' => '$0',
]);

it('refuses stored values containing line breaks or control characters and uploads nothing', function (string $value) {
    expect(fn () => app(UpdatePhpSettings::class)->execute($this->server, ['memory_limit' => $value]))
        ->toThrow(InvalidArgumentException::class);

    expect($this->uploads)->toBeEmpty();
})->with([
    'newline' => "128M\nauto_prepend_file=/tmp/x.php",
    'carriage return' => "128M\rauto_prepend_file=/tmp/x.php",
    'null byte' => "128M\0",
    'tab' => "128M\t",
]);
