<?php

namespace App\Models;

use App\Rules\AllowedProcessUser;
use App\Rules\NoControlCharacters;
use App\Rules\ValidCronExpression;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

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
     * prepend `cd {current release}/ && ` — otherwise `php artisan schedule:run`
     * fails to find artisan.
     *
     * @throws RuntimeException when a stored value is unsafe to write into the file.
     */
    public function cronLine(): string
    {
        $this->ensureSafeForCronFile();

        $command = $this->command;

        if ($this->site_id) {
            $this->loadMissing('site');

            if ($this->site) {
                $command = 'cd '.$this->site->currentPath().' && '.$command;
            }
        }

        return $this->frequency.' '.$this->user.' '.$command."\n";
    }

    /**
     * Re-check the values written into the cron.d file, so a job saved before
     * validation was tightened can't run as root or add extra cron lines.
     *
     * @throws RuntimeException
     */
    private function ensureSafeForCronFile(): void
    {
        $validator = Validator::make($this->only(['frequency', 'user', 'command']), [
            'frequency' => ['required', 'string', new NoControlCharacters, new ValidCronExpression],
            'user' => ['required', 'string', new AllowedProcessUser],
            'command' => ['required', 'string', new NoControlCharacters],
        ]);

        if ($validator->fails()) {
            throw new RuntimeException("Cron job {$this->id} was not written to the server: {$validator->errors()->first()}");
        }
    }
}
