<?php

namespace App\Http\Resources;

use App\Models\DatabaseUser;
use App\Models\ServerDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What the Connect dialog needs for one server database. Everything comes from
 * the server the database lives on, which can be a different server from the
 * site that uses it.
 *
 * @mixin ServerDatabase
 */
class DatabaseConnectionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array{server: array<string, mixed>, database: array<string, mixed>, databaseUsers: array<int, mixed>, sshUser: string, hasSshKey: bool}
     */
    public function toArray(Request $request): array
    {
        $databaseServer = $this->server;
        $usersWithAccess = $databaseServer->databaseUsers()
            ->get()
            ->filter(fn (DatabaseUser $user): bool => in_array($this->name, $user->databases ?? [], true))
            ->values();

        return [
            'server' => $databaseServer->only(['id', 'name', 'ip_address', 'ssh_port', 'database_type']),
            'database' => (new ServerDatabaseResource($this->resource))->resolve($request),
            'databaseUsers' => DatabaseUserResource::collection($usersWithAccess)->resolve($request),
            'sshUser' => config('server.user'),
            'hasSshKey' => $databaseServer->sshKeys()->where('user_id', $request->user()?->id)->exists(),
        ];
    }
}
