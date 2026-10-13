<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A period during which a site's uptime check kept failing. Opened when the
 * site is confirmed down and resolved when it next responds.
 */
class SiteIncident extends Model
{
    /** @use HasFactory<\Database\Factories\SiteIncidentFactory> */
    use HasFactory;

    protected $fillable = [
        'site_id',
        'reason',
        'started_at',
        'resolved_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
