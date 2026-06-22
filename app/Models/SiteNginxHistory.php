<?php

namespace App\Models;

use App\Enums\NginxHistoryEvent;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteNginxHistory extends Model
{
    /** @use HasFactory<\Database\Factories\SiteNginxHistoryFactory> */
    use HasFactory;

    protected $table = 'site_nginx_history';

    public $timestamps = false;

    protected $fillable = [
        'site_id',
        'site_nginx_file_id',
        'user_id',
        'event',
        'path',
        'old_path',
        'old_content',
        'new_content',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event' => NginxHistoryEvent::class,
            'created_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function nginxFile(): BelongsTo
    {
        return $this->belongsTo(SiteNginxFile::class, 'site_nginx_file_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
