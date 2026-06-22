<?php

namespace App\Models;

use App\Enums\NginxFileSection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SiteNginxFile extends Model
{
    /** @use HasFactory<\Database\Factories\SiteNginxFileFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'site_id',
        'site_domain_id',
        'section',
        'path',
        'content',
        'hash',
        'sync_status',
        'sync_error',
        'last_synced_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'section' => NginxFileSection::class,
            'last_synced_at' => 'datetime',
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

    public function domain(): BelongsTo
    {
        return $this->belongsTo(SiteDomain::class, 'site_domain_id');
    }

    public function history(): HasMany
    {
        return $this->hasMany(SiteNginxHistory::class);
    }

    public function absolutePath(): string
    {
        if ($this->section === NginxFileSection::Main) {
            return '/etc/nginx/sites-available/'.$this->path;
        }

        $this->loadMissing('domain.site');

        if ($this->domain === null) {
            return $this->path;
        }

        return $this->domain->nginxSnippetsBasePath().'/'.$this->path;
    }
}
