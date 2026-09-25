<?php

namespace App\Events;

use App\Concerns\QueuesBroadcasts;
use App\Models\Metric;
use App\Models\Server;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ServerMetricsUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, QueuesBroadcasts, SerializesModels;

    public function __construct(
        public Server $server,
        public Metric $metric,
    ) {}

    /**
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('server.'.$this->server->id),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'load' => $this->metric->load,
            'memory_total' => $this->metric->memory_total,
            'memory_used' => $this->metric->memory_used,
            'memory_free' => $this->metric->memory_free,
            'disk_total' => $this->metric->disk_total,
            'disk_used' => $this->metric->disk_used,
            'disk_free' => $this->metric->disk_free,
            'collected_at' => $this->metric->created_at->toIso8601String(),
        ];
    }

    public function broadcastAs(): string
    {
        return 'server.metrics.updated';
    }
}
