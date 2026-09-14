<?php

namespace App\Http\Resources;

use App\Enums\SiteDomainType;
use App\Models\Certificate;
use App\Models\Server;
use App\Support\SiteDomainDnsInstructions;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\SiteDomain
 */
class SiteDomainResource extends JsonResource
{
    /**
     * Days before expiry at which we consider the cert "expiring soon".
     * Mirrors Certificate::RENEWAL_WINDOW_DAYS.
     */
    private const RENEWAL_WINDOW_DAYS = 30;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $server = $request->route('server');
        if (! $server instanceof Server) {
            $server = $this->resource->site?->server;
        }

        $dnsRecords = $server
            ? SiteDomainDnsInstructions::records($server, $this->resource)
            : [];

        $sslExpiresAt = $this->type === SiteDomainType::Custom
            ? $this->ssl_expires_at
            : Certificate::query()->firstWhere('domain', '*.'.config('server.free_domain'))?->expires_at;

        return [
            'id' => $this->id,
            'ulid' => $this->ulid,
            'hostname' => $this->hostname,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'is_primary' => $this->is_primary,
            'wildcard_enabled' => $this->wildcard_enabled,
            'www_redirect' => $this->www_redirect->value,
            'www_redirect_label' => $this->www_redirect->label(),
            'is_enabled' => $this->is_enabled,
            'status' => $this->status->value,
            'is_verified' => $this->resource->isVerified(),
            'verified_at' => $this->verified_at?->toIso8601String(),
            'has_ssl' => $this->resource->hasSsl(),
            'ssl_enabled_at' => $this->ssl_enabled_at?->toIso8601String(),
            'ssl_expires_at' => $sslExpiresAt?->toIso8601String(),
            'ssl_status' => $this->sslStatus($sslExpiresAt),
            'dns_records' => $dnsRecords,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }

    private function sslStatus(?CarbonInterface $expiresAt): string
    {
        if ($expiresAt === null) {
            return 'none';
        }

        if ($expiresAt->isPast()) {
            return 'expired';
        }

        if ($expiresAt->isBefore(now()->addDays(self::RENEWAL_WINDOW_DAYS))) {
            return 'expiring_soon';
        }

        return 'valid';
    }
}
