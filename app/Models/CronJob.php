<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CronJob extends Model
{
    /** @use HasFactory<\Database\Factories\CronJobFactory> */
    use HasFactory;

    protected $fillable = [
        'server_id',
        'site_id',
        'command',
        'user',
        'frequency',
        'hidden',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'hidden' => 'boolean',
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
     * Render the `/etc/cron.d/`-format line for this job. Cron daemon runs
     * commands with cwd `/` and a minimal PATH, so for site-bound jobs we
     * prepend `cd {site_root} && ` — otherwise `php artisan schedule:run`
     * fails to find artisan.
     */
    public function cronLine(): string
    {
        $command = $this->command;

        if ($this->site_id) {
            $this->loadMissing('site');

            if ($this->site) {
                $command = 'cd '.$this->site->rootPath().' && '.$command;
            }
        }

        return $this->frequency.' '.$this->user.' '.$command."\n";
    }
}
