<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DatabaseUser extends Model
{
    /** @use HasFactory<\Database\Factories\DatabaseUserFactory> */
    use HasFactory;

    protected $fillable = [
        'server_id',
        'username',
        'password',
        'databases',
        'permission',
        'host',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'databases' => 'array',
            'password' => 'encrypted',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
