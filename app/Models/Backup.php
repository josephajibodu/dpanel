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
        'site_id',
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

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function storageProvider(): BelongsTo
    {
        return $this->belongsTo(StorageProvider::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Whether this backup is of a site's SQLite file rather than a server database.
     */
    public function isSqlite(): bool
    {
        return $this->site_id !== null;
    }

    /**
     * The server the backed-up data lives on.
     */
    public function targetServer(): Server
    {
        return $this->isSqlite() ? $this->site->server : $this->serverDatabase->server;
    }

    /**
     * Human-readable name of what was backed up (database name or site domain).
     */
    public function targetName(): string
    {
        return $this->isSqlite() ? $this->site->domain : $this->serverDatabase->name;
    }

    /**
     * The backups page this backup is listed on.
     */
    public function indexUrl(): string
    {
        $server = $this->targetServer();

        return $this->isSqlite()
            ? route('servers.sites.backups.index', [$server->team, $server, $this->site])
            : route('servers.databases.backups.index', [$server->team, $server, $this->serverDatabase]);
    }
}
