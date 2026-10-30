<?php

namespace App\Http\Resources;

use App\Models\ServerDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ServerDatabase
 */
class ServerDatabaseResource extends JsonResource
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
            'server_name' => $this->whenLoaded('server', fn () => $this->server->name),
            'name' => $this->name,
            'collation' => $this->collation,
            'charset' => $this->charset,
            'status' => $this->status,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
