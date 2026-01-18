<?php

namespace App\Models;

use App\Enums\RepositoryProvider;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SourceControlAccount extends Model
{
    /** @use HasFactory<\Database\Factories\SourceControlAccountFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'user_id',
        'provider',
        'provider_user_id',
        'provider_username',
        'name',
        'email',
        'avatar_url',
        'token',
        'refresh_token',
        'token_expires_at',
        'connected_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => RepositoryProvider::class,
            'token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'connected_at' => 'datetime',
        ];
    }

    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Check if the OAuth token is expired or will expire soon.
     */
    public function isTokenExpired(): bool
    {
        if (! $this->token_expires_at) {
            return false;
        }

        // Consider expired if within 5 minutes of expiry
        return $this->token_expires_at->subMinutes(5)->isPast();
    }
}
