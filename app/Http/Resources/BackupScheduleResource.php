<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\BackupSchedule
 */
class BackupScheduleResource extends JsonResource
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
            'storage_provider_id' => $this->storage_provider_id,
            'frequency' => $this->frequency,
            'retention_count' => $this->retention_count,
            'enabled' => $this->enabled,
            'next_run_at' => $this->next_run_at?->toIso8601String(),
        ];
    }
}
