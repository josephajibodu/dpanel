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
            'root_path' => $this->rootPath(),
            'web_root' => $this->webRoot(),
            'repository' => $this->repository,
            'short_repository' => $this->shortRepository(),
            'repository_url' => $this->repositoryUrl(),
            'repository_provider' => $this->repository_provider?->value,
            'repository_provider_label' => $this->repository_provider?->label(),
            'branch' => $this->branch,
            'project_type' => $this->project_type?->value,
            'project_type_label' => $this->project_type?->label(),
            'php_version' => $this->php_version,
            'php_binary' => $this->php_version ? "php{$this->php_version}" : null,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'status_color' => $this->status->color(),
            'auto_deploy' => $this->auto_deploy,
            'server' => new ServerResource($this->whenLoaded('server')),
            'latest_deployment' => new DeploymentResource($this->whenLoaded('latestDeployment')),
            'deployments' => DeploymentResource::collection($this->whenLoaded('deployments')),
            'deploy_script' => $this->whenLoaded('deployScript', fn () => $this->deployScript?->script),
            'deployment_started_at' => $this->deployment_started_at?->toIso8601String(),
            'deployment_finished_at' => $this->deployment_finished_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
