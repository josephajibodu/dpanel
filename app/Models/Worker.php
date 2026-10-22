<?php

namespace App\Models;

use App\Rules\AllowedProcessUser;
use App\Rules\NoControlCharacters;
use App\Rules\SafeAbsolutePath;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

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
     * tied to a site we emit `directory=` pointing at its current release —
     * without it, supervisord starts the child process from `/`, which breaks
     * the common Laravel pattern (`php artisan queue:work`) because artisan
     * and vendor/ only exist inside a release, not the site's base directory.
     *
     * @throws RuntimeException when a stored value is unsafe to write into the file.
     */
    public function supervisorConfig(string $programName): string
    {
        $this->ensureSafeForConfigFile();

        $lines = ['[program:'.$programName.']'];

        if ($this->site_id) {
            $this->loadMissing('site');

            if ($this->site) {
                $lines[] = 'directory='.$this->site->currentPath();
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

    /**
     * Re-check the values written into the supervisor file, so a worker saved
     * before validation was tightened can't add extra settings (such as a
     * second `user=root` line) to its program block.
     *
     * @throws RuntimeException
     */
    private function ensureSafeForConfigFile(): void
    {
        $validator = Validator::make($this->only(['command', 'user', 'stdout_logfile']), [
            'command' => ['required', 'string', new NoControlCharacters],
            'user' => ['required', 'string', new AllowedProcessUser],
            'stdout_logfile' => ['nullable', 'string', new SafeAbsolutePath],
        ]);

        if ($validator->fails()) {
            throw new RuntimeException("Worker {$this->id} was not written to the server: {$validator->errors()->first()}");
        }
    }
}
