<?php

namespace App\Models;

use App\Enums\SiteDomainStatus;
use App\Enums\SiteDomainType;
use App\Enums\WwwRedirect;
use Database\Factories\SiteDomainFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SiteDomain extends Model
{
    /** @use HasFactory<SiteDomainFactory> */
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
        'ssl_expires_at',
        'ssl_expiry_alert_threshold',
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
            'ssl_expires_at' => 'datetime',
            'ssl_expiry_alert_threshold' => 'integer',
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

    /**
     * Whether this is a bare registered domain (example.com, example.com.ng)
     * rather than a subdomain of one. Only a bare domain has a `www.` twin
     * worth redirecting.
     */
    public function isApex(): bool
    {
        $labels = explode('.', strtolower($this->hostname));

        if (count($labels) === 2) {
            return true;
        }

        // Country domains registered under a second level: example.com.ng, example.co.uk.
        return count($labels) === 3
            && strlen($labels[2]) === 2
            && in_array($labels[1], ['co', 'com', 'org', 'net', 'gov', 'edu', 'ac'], true);
    }

    /**
     * The www redirect that actually applies. A redirect set on a subdomain
     * is ignored, since `www.app.example.com` isn't an address anyone uses.
     */
    public function effectiveWwwRedirect(): WwwRedirect
    {
        return $this->isApex() ? $this->www_redirect : WwwRedirect::None;
    }

    /**
     * The `www.` twin of a bare domain.
     */
    public function wwwHostname(): string
    {
        return 'www.'.$this->hostname;
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

    /**
     * Folder holding the snippet files users add for this domain, on the
     * site's server's web server.
     */
    public function nginxSnippetsBasePath(): string
    {
        $this->loadMissing('site.server');

        return $this->site->server->webServer()->snippetsBasePath($this->site->ulid, $this->hostname);
    }
}
