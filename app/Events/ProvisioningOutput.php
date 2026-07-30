<?php

namespace App\Events;

use App\Models\Server;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ProvisioningOutput implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public Server $server,
        public string $line,
        public string $type = 'output',
    ) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("server.{$this->server->id}"),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'provisioning.output';
    }

    /**
     * Get the data to broadcast.
     * Send log line data for streaming (this is incremental, not heavy).
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'line' => $this->line,
            'type' => $this->type,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
