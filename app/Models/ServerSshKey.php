<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class ServerSshKey extends Pivot
{
    protected $table = 'server_ssh_key';

    protected function casts(): array
    {
        return [
            'synced_at' => 'datetime',
        ];
    }
}
