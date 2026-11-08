<?php

namespace App\Models;

use Database\Factories\SiteEnvironmentHistoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One save of a site's environment: who saved it, which keys it added,
 * changed or removed, and the file before and after (encrypted).
 */
class SiteEnvironmentHistory extends Model
{
    /** @use HasFactory<SiteEnvironmentHistoryFactory> */
    use HasFactory;

    protected $table = 'site_environment_history';

    public $timestamps = false;

    protected $fillable = [
        'site_id',
        'user_id',
        'added_keys',
        'changed_keys',
        'removed_keys',
        'old_content',
        'new_content',
        'created_at',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'old_content',
        'new_content',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'added_keys' => 'array',
            'changed_keys' => 'array',
            'removed_keys' => 'array',
            'old_content' => 'encrypted',
            'new_content' => 'encrypted',
            'created_at' => 'datetime',
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
