<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\ProviderAccount
 */
class ProviderAccountResource extends JsonResource
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
            'name' => $this->name,
            'is_valid' => $this->is_valid,
            'validated_at' => $this->validated_at?->toIso8601String(),
            'servers_count' => $this->whenCounted('servers'),
            'servers' => ServerResource::collection($this->whenLoaded('servers')),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
