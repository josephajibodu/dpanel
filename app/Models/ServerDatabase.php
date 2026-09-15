<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ServerDatabase extends Model
{
    /** @use HasFactory<\Database\Factories\ServerDatabaseFactory> */
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
}
