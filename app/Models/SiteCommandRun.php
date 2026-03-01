<?php

namespace App\Models;

use App\Enums\SiteCommandRunStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteCommandRun extends Model
{
    /** @use HasFactory<\Database\Factories\SiteCommandRunFactory> */
    use HasFactory;

    protected $fillable = [
        'site_id',
        'user_id',
        'command',
        'output',
        'status',
        'exit_code',
        'started_at',
        'finished_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SiteCommandRunStatus::class,
            'exit_code' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
