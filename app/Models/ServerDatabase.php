<?php

namespace App\Models;

use Database\Factories\ServerDatabaseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ServerDatabase extends Model
{
    /** @use HasFactory<ServerDatabaseFactory> */
    use HasFactory;

    protected $table = 'server_databases';

    protected $fillable = [
        'server_id',
        'name',
        'collation',
        'charset',
        'status',
    ];

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function backupSchedule(): HasOne
    {
        return $this->hasOne(BackupSchedule::class);
    }

    public function backups(): HasMany
    {
        return $this->hasMany(Backup::class);
    }

    /**
     * The read-write user an imported Postgres dump's tables are handed to:
     * the oldest one, which is normally the user the site connects as.
     */
    public function importOwner(): ?DatabaseUser
    {
        return DatabaseUser::query()
            ->where('server_id', $this->server_id)
            ->where('permission', 'readwrite')
            ->where('status', 'ready')
            ->whereJsonContains('databases', $this->name)
            ->oldest('id')
            ->first();
    }
}
