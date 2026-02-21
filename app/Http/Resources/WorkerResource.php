<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Worker
 */
class WorkerResource extends JsonResource
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
            'server_id' => $this->server_id,
            'site_id' => $this->site_id,
            'command' => $this->command,
            'user' => $this->user,
            'status' => $this->status,
            'name' => $this->name,
            'numprocs' => $this->numprocs,
            'auto_start' => $this->auto_start,
            'auto_restart' => $this->auto_restart,
            'redirect_stderr' => $this->redirect_stderr,
            'stdout_logfile' => $this->stdout_logfile,
            'site' => $this->whenLoaded('site', fn () => [
                'id' => $this->site->id,
                'domain' => $this->site->domain,
            ]),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
