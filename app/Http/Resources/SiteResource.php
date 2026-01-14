<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Site
 */
class SiteResource extends JsonResource
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
            'domain' => $this->domain,
            'aliases' => $this->aliases,
            'directory' => $this->directory,
            'repository' => $this->repository,
            'repository_provider' => $this->repository_provider,
            'branch' => $this->branch,
            'project_type' => $this->project_type,
            'php_version' => $this->php_version,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'status_color' => $this->status->color(),
            'auto_deploy' => $this->auto_deploy,
            'server' => new ServerResource($this->whenLoaded('server')),
            'latest_deployment' => new DeploymentResource($this->whenLoaded('latestDeployment')),
            'deployment_started_at' => $this->deployment_started_at?->toIso8601String(),
            'deployment_finished_at' => $this->deployment_finished_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
