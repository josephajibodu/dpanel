<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\SourceControlAccount
 */
class SourceControlAccountResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ulid' => $this->ulid,
            'provider' => $this->provider->value,
            'provider_label' => $this->provider->label(),
            'provider_user_id' => $this->provider_user_id,
            'provider_username' => $this->provider_username,
            'name' => $this->name,
            'email' => $this->email,
            'avatar_url' => $this->avatar_url,
            'connected_at' => $this->connected_at->toIso8601String(),
            'token_expires_at' => $this->token_expires_at?->toIso8601String(),
            'is_token_expired' => $this->isTokenExpired(),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
