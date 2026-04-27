<?php

namespace App\Models;

use App\Enums\SiteDomainType;
use App\Enums\WwwRedirect;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        'cloudflare_dns_record_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => SiteDomainType::class,
            'is_primary' => 'boolean',
            'wildcard_enabled' => 'boolean',
            'www_redirect' => WwwRedirect::class,
            'is_enabled' => 'boolean',
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
}
