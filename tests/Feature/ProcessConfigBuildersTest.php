<?php

use App\Actions\CronJob\CreateCronJob;
use App\Actions\CronJob\EnableCronJob;
use App\Actions\CronJob\UpdateCronJob;
use App\Actions\Worker\CreateWorker;
use App\Actions\Worker\UpdateWorker;
use App\Enums\ServerStatus;
use App\Models\CronJob;
use App\Models\Server;
use App\Models\Site;
use App\Models\Worker;
use App\Services\Ssh\SshConnection;
use App\Services\Ssh\SshService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->server = Server::factory()->create();
    $this->site = Site::factory()->forServer($this->server)->create();
});

// -------- Worker::supervisorConfig() ----------------------------------------

it('includes directory= for site-bound workers', function () {
    $worker = Worker::factory()->forSite($this->site)->create([
        'command' => 'php artisan queue:work',
        'user' => 'artisan',
        'numprocs' => 1,
        'auto_start' => true,
        'auto_restart' => true,
        'redirect_stderr' => true,
    ]);

    $config = $worker->supervisorConfig('flitops-worker-'.$worker->id);

    expect($config)->toContain('directory='.$this->site->currentPath())
        ->and($config)->toContain('command=php artisan queue:work')
        ->and($config)->toContain('user=artisan');
});

it('omits directory= when the worker is server-wide', function () {
    $worker = Worker::factory()->create([
        'server_id' => $this->server->id,
        'site_id' => null,
        'command' => '/usr/local/bin/myproc',
    ]);

    $config = $worker->supervisorConfig('flitops-worker-'.$worker->id);

    expect($config)->not->toContain('directory=');
});

it('adds process_name when numprocs > 1', function () {
    $worker = Worker::factory()->create([
        'server_id' => $this->server->id,
        'site_id' => null,
        'numprocs' => 4,
    ]);

    $config = $worker->supervisorConfig('flitops-worker-'.$worker->id);

    expect($config)->toContain('numprocs=4')
        ->and($config)->toContain('process_name=%(program_name)s_%(process_num)02d');
});

it('always writes output to a log file in the root-only supervisor directory', function () {
    $worker = Worker::factory()->create(['server_id' => $this->server->id]);

    expect($worker->logFilePath())->toBe('/var/log/supervisor/flitops-worker-'.$worker->id.'.log')
        ->and($worker->supervisorConfig('w'))->toContain('stdout_logfile='.$worker->logFilePath()."\n");
});

// -------- CronJob::cronLine() -----------------------------------------------

it('prepends cd {site_root} for site-bound cron jobs', function () {
    $cron = CronJob::factory()->forSite($this->site)->create([
        'command' => 'php artisan schedule:run',
        'user' => 'artisan',
        'frequency' => '* * * * *',
    ]);

    $line = $cron->cronLine();

    expect($line)->toBe(
        '* * * * * artisan cd '.$this->site->currentPath().' && php artisan schedule:run'."\n"
    );
});

it('does not prepend cd for server-wide cron jobs', function () {
    $cron = CronJob::factory()->create([
        'server_id' => $this->server->id,
        'site_id' => null,
        'command' => '/usr/bin/myscript',
        'user' => 'artisan',
        'frequency' => '0 * * * *',
    ]);

    $line = $cron->cronLine();

    expect($line)->toBe("0 * * * * artisan /usr/bin/myscript\n");
});

// -------- Unsafe stored values (saved before validation was tightened) ------

it('refuses to build a supervisor block from unsafe stored values', function (array $attributes) {
    $worker = Worker::factory()->create(['server_id' => $this->server->id, ...$attributes]);

    expect(fn () => $worker->supervisorConfig('w'))
        ->toThrow(RuntimeException::class, "Worker {$worker->id} was not written to the server");
})->with([
    'root user' => [['user' => 'root']],
    'arbitrary user' => [['user' => 'www-data']],
    'newline in user' => [['user' => "artisan\nuser=root"]],
    'newline in command' => [['command' => "php artisan queue:work\nuser=root"]],
]);

it('refuses to build a cron line from unsafe stored values', function (array $attributes) {
    $cron = CronJob::factory()->create(['server_id' => $this->server->id, ...$attributes]);

    expect(fn () => $cron->cronLine())
        ->toThrow(RuntimeException::class, "Cron job {$cron->id} was not written to the server");
})->with([
    'root user' => [['user' => 'root']],
    'arbitrary user' => [['user' => 'www-data']],
    'newline in user' => [['user' => "artisan\n* * * * * root id"]],
    'newline in command' => [['command' => "php artisan schedule:run\n* * * * * root id"]],
    'newline in frequency' => [['frequency' => "* * * * *\n* * * * * root id #"]],
    'invalid frequency' => [['frequency' => 'every minute']],
]);

/**
 * An SSH connection that fails the test if anything is uploaded to the server.
 */
function sshServiceThatMustNotUpload(): SshService
{
    $connection = Mockery::mock(SshConnection::class)->makePartial();
    $connection->shouldNotReceive('upload');
    $connection->shouldNotReceive('sudo');
    $connection->shouldReceive('disconnect')->andReturn(null);

    $sshService = Mockery::mock(SshService::class);
    $sshService->shouldReceive('connect')->andReturn($connection);

    return $sshService;
}

it('does not upload a supervisor file for a worker with unsafe stored values', function (string $action) {
    $this->server->update(['status' => ServerStatus::Active]);
    $worker = Worker::factory()->create(['server_id' => $this->server->id, 'user' => 'root']);
    $this->app->instance(SshService::class, sshServiceThatMustNotUpload());

    expect(fn () => app($action)->execute($worker))->toThrow(RuntimeException::class, 'was not written to the server');
})->with([CreateWorker::class, UpdateWorker::class]);

it('does not upload a cron file for a cron job with unsafe stored values', function (string $action) {
    $this->server->update(['status' => ServerStatus::Active]);
    $cron = CronJob::factory()->create([
        'server_id' => $this->server->id,
        'command' => "php artisan schedule:run\n* * * * * root id",
    ]);
    $this->app->instance(SshService::class, sshServiceThatMustNotUpload());

    expect(fn () => app($action)->execute($cron))->toThrow(RuntimeException::class, 'was not written to the server');
})->with([CreateCronJob::class, UpdateCronJob::class, EnableCronJob::class]);
