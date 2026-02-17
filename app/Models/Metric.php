<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Metric extends Model
{
    /** @use HasFactory<\Database\Factories\MetricFactory> */
    use HasFactory;

    protected $fillable = [
        'server_id',
        'load',
        'memory_total',
        'memory_used',
        'memory_free',
        'disk_total',
        'disk_used',
        'disk_free',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'load' => 'float',
            'memory_total' => 'integer',
            'memory_used' => 'integer',
            'memory_free' => 'integer',
            'disk_total' => 'integer',
            'disk_used' => 'integer',
            'disk_free' => 'integer',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
