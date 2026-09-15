<?php

namespace App\Models;

use App\Enums\StorageProviderType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StorageProvider extends Model
{
    /** @use HasFactory<\Database\Factories\StorageProviderFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'team_id',
        'user_id',
        'type',
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
            'type' => StorageProviderType::class,
            'credentials' => 'encrypted:array',
            'is_valid' => 'boolean',
            'validated_at' => 'datetime',
        ];
    }

    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function backupSchedules(): HasMany
    {
        return $this->hasMany(BackupSchedule::class);
    }

    public function backups(): HasMany
    {
        return $this->hasMany(Backup::class);
    }
}
