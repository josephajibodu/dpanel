<?php

namespace App\Models;

use App\Enums\Provider;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProviderAccount extends Model
{
    /** @use HasFactory<\Database\Factories\ProviderAccountFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'user_id',
        'provider',
        'name',
        'credentials',
        'is_valid',
        'validated_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => Provider::class,
            'credentials' => 'encrypted:array',
            'is_valid' => 'boolean',
            'validated_at' => 'datetime',
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

    public function servers(): HasMany
    {
        return $this->hasMany(Server::class);
    }
}
