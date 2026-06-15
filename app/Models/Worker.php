<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Worker extends Model
{
    /** @use HasFactory<\Database\Factories\WorkerFactory> */
    use HasFactory;

    protected $fillable = [
        'server_id',
        'site_id',
        'command',
        'user',
        'auto_start',
        'auto_restart',
        'numprocs',
        'redirect_stderr',
        'stdout_logfile',
        'status',
        'name',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'auto_start' => 'boolean',
            'auto_restart' => 'boolean',
            'redirect_stderr' => 'boolean',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * Build the supervisord program block for this worker. When the worker is
     * tied to a site we emit `directory=` pointing at the site root — without
     * it, supervisord starts the child process from `/`, which breaks the
     * common Laravel pattern (`php artisan queue:work`) because artisan and
     * vendor/ are only reachable from the site root.
     */
    public function supervisorConfig(string $programName): string
    {
        $lines = ['[program:'.$programName.']'];

        if ($this->site_id) {
            $this->loadMissing('site');

            if ($this->site) {
                $lines[] = 'directory='.$this->site->rootPath();
            }
        }

        $lines[] = 'command='.$this->command;
        $lines[] = 'user='.$this->user;
        $lines[] = 'numprocs='.$this->numprocs;
        $lines[] = 'autostart='.($this->auto_start ? 'true' : 'false');
        $lines[] = 'autorestart='.($this->auto_restart ? 'true' : 'false');
        $lines[] = 'redirect_stderr='.($this->redirect_stderr ? 'true' : 'false');

        if ($this->numprocs > 1) {
            $lines[] = 'process_name=%(program_name)s_%(process_num)02d';
        }

        if ($this->stdout_logfile) {
            $lines[] = 'stdout_logfile='.$this->stdout_logfile;
        }

        return implode("\n", $lines)."\n";
    }
}
