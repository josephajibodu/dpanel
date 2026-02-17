<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FirewallRule extends Model
{
    /** @use HasFactory<\Database\Factories\FirewallRuleFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'server_id',
        'type',
        'protocol',
        'port',
        'source',
        'mask',
        'note',
        'status',
    ];

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
