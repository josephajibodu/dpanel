<?php

use App\Models\CronJob;
use App\Models\Server;
use App\Models\Site;
use App\Models\Worker;
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
        'stdout_logfile' => null,
    ]);

    $config = $worker->supervisorConfig('flitops-worker-'.$worker->id);

    expect($config)->toContain('directory='.$this->site->rootPath())
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

it('writes stdout_logfile only when set', function () {
    $worker = Worker::factory()->create([
        'server_id' => $this->server->id,
        'site_id' => null,
        'stdout_logfile' => null,
    ]);
    expect($worker->supervisorConfig('w'))->not->toContain('stdout_logfile=');

    $worker2 = Worker::factory()->create([
        'server_id' => $this->server->id,
        'site_id' => null,
        'stdout_logfile' => '/var/log/x.log',
    ]);
    expect($worker2->supervisorConfig('w'))->toContain('stdout_logfile=/var/log/x.log');
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
        '* * * * * artisan cd '.$this->site->rootPath().' && php artisan schedule:run'."\n"
    );
});

it('does not prepend cd for server-wide cron jobs', function () {
    $cron = CronJob::factory()->create([
        'server_id' => $this->server->id,
        'site_id' => null,
        'command' => '/usr/bin/myscript',
        'user' => 'root',
        'frequency' => '0 * * * *',
    ]);

    $line = $cron->cronLine();

    expect($line)->toBe("0 * * * * root /usr/bin/myscript\n");
});
