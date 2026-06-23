<?php

namespace App\Models;

use App\Enums\SiteDomainStatus;
use App\Enums\SiteDomainType;
use App\Enums\WwwRedirect;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SiteDomain extends Model
{
    /** @use HasFactory<\Database\Factories\SiteDomainFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'site_id',
        'hostname',
        'type',
        'is_primary',
        'wildcard_enabled',
        'www_redirect',
        'is_enabled',
        'status',
        'cloudflare_dns_record_id',
        'verified_at',
        'ssl_enabled_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => SiteDomainType::class,
            'status' => SiteDomainStatus::class,
            'is_primary' => 'boolean',
            'wildcard_enabled' => 'boolean',
            'www_redirect' => WwwRedirect::class,
            'is_enabled' => 'boolean',
            'verified_at' => 'datetime',
            'ssl_enabled_at' => 'datetime',
        ];
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    public function hasSsl(): bool
    {
        return $this->ssl_enabled_at !== null;
    }

    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function nginxFiles(): HasMany
    {
        return $this->hasMany(SiteNginxFile::class);
    }

    public function nginxSnippetsBasePath(): string
    {
        $this->loadMissing('site');

        return "/etc/nginx/flitops-conf/{$this->site->ulid}/{$this->hostname}";
    }
}
