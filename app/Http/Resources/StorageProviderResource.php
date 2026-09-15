<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\StorageProvider
 */
class StorageProviderResource extends JsonResource
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
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'name' => $this->name,
            'bucket' => $this->credentials['bucket'] ?? null,
            'is_valid' => $this->is_valid,
            'validated_at' => $this->validated_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
