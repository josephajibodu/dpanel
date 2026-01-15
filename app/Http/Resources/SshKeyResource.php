<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\SshKey
 */
class SshKeyResource extends JsonResource
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
            'name' => $this->name,
            'fingerprint' => $this->fingerprint,
            'public_key_preview' => $this->getPublicKeyPreview(),
            'servers' => $this->whenLoaded('servers', function () {
                return $this->servers->map(fn ($server) => [
                    'id' => $server->id,
                    'ulid' => $server->ulid,
                    'name' => $server->name,
                    'status' => $server->pivot->status,
                    'synced_at' => $server->pivot->synced_at?->toIso8601String(),
                ]);
            }),
            'servers_count' => $this->whenCounted('servers'),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }

    private function getPublicKeyPreview(): string
    {
        $key = $this->public_key;
        if (strlen($key) <= 50) {
            return $key;
        }

        return substr($key, 0, 30).'...'.substr($key, -20);
    }
}
