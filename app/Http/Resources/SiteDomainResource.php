<?php

namespace App\Http\Resources;

use App\Models\Server;
use App\Support\SiteDomainDnsInstructions;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\SiteDomain
 */
class SiteDomainResource extends JsonResource
{
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
            'is_verified' => $this->resource->isVerified(),
            'verified_at' => $this->verified_at?->toIso8601String(),
            'has_ssl' => $this->resource->hasSsl(),
            'ssl_enabled_at' => $this->ssl_enabled_at?->toIso8601String(),
            'dns_records' => $dnsRecords,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
