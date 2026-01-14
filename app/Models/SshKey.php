<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class SshKey extends Model
{
    /** @use HasFactory<\Database\Factories\SshKeyFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'user_id',
        'name',
        'public_key',
        'fingerprint',
    ];

    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function servers(): BelongsToMany
    {
        return $this->belongsToMany(Server::class, 'server_ssh_key')
            ->withPivot(['status', 'synced_at'])
            ->withTimestamps();
    }
}
