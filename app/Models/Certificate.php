<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Certificate extends Model
{
    /** @use HasFactory<\Database\Factories\CertificateFactory> */
    use HasFactory;

    /**
     * Days before expiry at which we consider the cert due for renewal.
     */
    public const RENEWAL_WINDOW_DAYS = 30;

    protected $fillable = [
        'domain',
        'certificate_path',
        'private_key_path',
        'chain_path',
        'expires_at',
        'last_renewed_at',
        'last_distribution_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'last_renewed_at' => 'datetime',
            'last_distribution_at' => 'datetime',
        ];
    }

    /**
     * True when the certificate is missing, expired, or within the renewal window.
     */
    public function needsRenewal(): bool
    {
        if ($this->expires_at === null) {
            return true;
        }

        return $this->expires_at->isBefore(now()->addDays(self::RENEWAL_WINDOW_DAYS));
    }
}
