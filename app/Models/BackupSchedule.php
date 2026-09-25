<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BackupSchedule extends Model
{
    /** @use HasFactory<\Database\Factories\BackupScheduleFactory> */
    use HasFactory;

    protected $fillable = [
        'server_database_id',
        'site_id',
        'storage_provider_id',
        'frequency',
        'retention_count',
        'enabled',
        'next_run_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'retention_count' => 'integer',
            'enabled' => 'boolean',
            'next_run_at' => 'datetime',
        ];
    }

    public function serverDatabase(): BelongsTo
    {
        return $this->belongsTo(ServerDatabase::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function storageProvider(): BelongsTo
    {
        return $this->belongsTo(StorageProvider::class);
    }

    /**
     * The next occurrence after now, based on this schedule's frequency.
     */
    public function computeNextRunAt(): \DateTimeInterface
    {
        return match ($this->frequency) {
            'hourly' => now()->addHour(),
            'weekly' => now()->addWeek(),
            default => now()->addDay(),
        };
    }
}
