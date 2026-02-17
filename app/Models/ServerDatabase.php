<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
}
