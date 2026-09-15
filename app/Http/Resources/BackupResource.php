<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Backup
 */
class BackupResource extends JsonResource
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
            'status' => $this->status,
            'triggered_by' => $this->triggered_by,
            'triggered_by_user' => $this->whenLoaded('user', fn () => $this->user?->name),
            'size_bytes' => $this->size_bytes,
            'verified_at' => $this->verified_at?->toIso8601String(),
            'error_message' => $this->error_message,
            'restore_status' => $this->restore_status,
            'restored_at' => $this->restored_at?->toIso8601String(),
            'restore_error' => $this->restore_error,
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
