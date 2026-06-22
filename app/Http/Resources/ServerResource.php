<?php

namespace App\Http\Resources;

use App\Enums\ProvisioningStep;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Server
 */
class ServerResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $currentProvisioningStep = $this->provisioning_step ?? ProvisioningStep::Pending;

        return [
            'id' => $this->id,
            'ulid' => $this->ulid,
            'name' => $this->name,
            'provider' => $this->provider->value,
            'provider_label' => $this->provider->label(),
            'provider_account' => new ProviderAccountResource($this->whenLoaded('providerAccount')),
            'region' => $this->region,
            'size' => $this->size,
            'ip_address' => $this->ip_address,
            'private_ip_address' => $this->private_ip_address,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'status_color' => $this->status->color(),
            'provisioning_step' => [
                'value' => $currentProvisioningStep->value,
                'label' => $currentProvisioningStep->label(),
                'description' => $currentProvisioningStep->description(),
            ],
            'provisioning_steps' => collect(ProvisioningStep::displayableSteps())
                ->map(fn (ProvisioningStep $step): array => [
                    'value' => $step->value,
                    'label' => $step->label(),
                    'description' => $step->description(),
                ])
                ->values()
                ->all(),
            'error_message' => $this->error_message,
            'php_version' => $this->php_version,
            'database_type' => $this->database_type,
            'ssh_port' => $this->ssh_port,
            'sites_count' => $this->whenCounted('sites'),
            'sites' => SiteResource::collection($this->whenLoaded('sites')),
            'provisioned_at' => $this->provisioned_at?->toIso8601String(),
            'last_ssh_connection_at' => $this->last_ssh_connection_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
