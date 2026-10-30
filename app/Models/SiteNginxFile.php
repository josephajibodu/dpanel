<?php

namespace App\Models;

use App\Enums\NginxFileSection;
use Database\Factories\SiteNginxFileFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SiteNginxFile extends Model
{
    /** @use HasFactory<SiteNginxFileFactory> */
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
        return $this->absolutePathFor($this->path);
    }

    /**
     * Where a file at the given path (relative to its section's folder) lives
     * on the server.
     */
    public function absolutePathFor(string $path): string
    {
        if ($this->section === NginxFileSection::Main) {
            $this->loadMissing('site.server');

            return $this->site->server->webServer()->siteConfigPaths($path)[0];
        }

        $this->loadMissing('domain.site');

        if ($this->domain === null) {
            return $path;
        }

        return $this->domain->nginxSnippetsBasePath().'/'.$path;
    }
}
