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
     * Used to decide whether it's worth *attempting* a renewal.
     */
    public function needsRenewal(): bool
    {
        if ($this->expires_at === null) {
            return true;
        }

        return $this->expires_at->isBefore(now()->addDays(self::RENEWAL_WINDOW_DAYS));
    }

    /**
     * True when the certificate is missing or has already expired — i.e. it
     * would fail in a browser right now. Stricter than needsRenewal(): a cert
     * expiring in three weeks needsRenewal() but isn't expired() yet, so it's
     * still safe to install on a new site if a renewal attempt fails.
     */
    public function isExpired(): bool
    {
        return $this->expires_at === null || $this->expires_at->isPast();
    }
}
