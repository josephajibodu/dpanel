<?php

namespace App\Models;

use App\Enums\DeploymentStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Deployment extends Model
{
    /** @use HasFactory<\Database\Factories\DeploymentFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'site_id',
        'user_id',
        'commit_hash',
        'commit_message',
        'commit_author',
        'status',
        'started_at',
        'finished_at',
        'duration_seconds',
        'triggered_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DeploymentStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'duration_seconds' => 'integer',
        ];
    }

    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(DeploymentLog::class);
    }
}
