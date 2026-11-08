<?php

namespace App\Models;

use App\Enums\ActivityEvent;
use Database\Factories\ActivityLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A record of who did what in a team: the user (null for FlitOps itself),
 * the thing they acted on, the kind of event, and non-secret details.
 * Never put secret values in `properties`.
 */
class ActivityLog extends Model
{
    /** @use HasFactory<ActivityLogFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'team_id',
        'user_id',
        'subject_type',
        'subject_id',
        'event',
        'properties',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event' => ActivityEvent::class,
            'properties' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    public static function record(Team $team, ActivityEvent $event, ?Model $subject = null, array $properties = [], ?User $user = null): self
    {
        return self::create([
            'team_id' => $team->id,
            'user_id' => $user?->id,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'event' => $event,
            'properties' => $properties,
        ]);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
