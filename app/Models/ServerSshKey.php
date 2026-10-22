<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * One SSH key placed on one Linux user of a server: the server user (full
 * access) or an isolated site's own user (that site only).
 */
class ServerSshKey extends Pivot
{
    protected $table = 'server_ssh_key';

    public $incrementing = true;

    /**
     * The placements of a key on a server's Linux user.
     *
     * @param  Builder<ServerSshKey>  $query
     */
    public function scopePlacement(Builder $query, int $sshKeyId, int $serverId, string $systemUser): void
    {
        $query->where('ssh_key_id', $sshKeyId)
            ->where('server_id', $serverId)
            ->where('system_user', $systemUser);
    }

    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    protected function casts(): array
    {
        return [
            'synced_at' => 'datetime',
        ];
    }
}
