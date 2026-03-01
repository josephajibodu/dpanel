<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\SiteCommandRun
 */
class SiteCommandRunResource extends JsonResource
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
            'command' => $this->command,
            'output' => $this->when($this->includeOutput(), $this->output),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'status_color' => $this->status->color(),
            'exit_code' => $this->exit_code,
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ]),
        ];
    }

    /**
     * Include full output (for show endpoint); exclude from index to keep payload small.
     */
    public function withOutput(): static
    {
        $this->includeOutput = true;

        return $this;
    }

    private bool $includeOutput = false;

    private function includeOutput(): bool
    {
        return $this->includeOutput;
    }
}
