<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Backup extends Model
{
    /** @use HasFactory<\Database\Factories\BackupFactory> */
    use HasFactory;

    protected $fillable = [
        'server_database_id',
        'storage_provider_id',
        'user_id',
        'status',
        'triggered_by',
        'storage_path',
        'size_bytes',
        'verified_at',
        'error_message',
        'restore_status',
        'restored_at',
        'restore_error',
        'started_at',
        'finished_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'verified_at' => 'datetime',
            'restored_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function serverDatabase(): BelongsTo
    {
        return $this->belongsTo(ServerDatabase::class);
    }

    public function storageProvider(): BelongsTo
    {
        return $this->belongsTo(StorageProvider::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
