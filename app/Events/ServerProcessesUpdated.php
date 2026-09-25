<?php

namespace App\Events;

use App\Concerns\QueuesBroadcasts;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ServerProcessesUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, QueuesBroadcasts, SerializesModels;

    public function __construct(
        public int $serverId,
    ) {}

    /**
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('server.'.$this->serverId),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'server_id' => $this->serverId,
        ];
    }

    public function broadcastAs(): string
    {
        return 'server.processes.updated';
    }
}
