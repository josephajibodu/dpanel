<?php

namespace App\Events;

use App\Models\Deployment;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DeploymentStatusChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public Deployment $deployment,
        public string $event, // started, finished, failed
    ) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("deployments.{$this->deployment->id}"),
            new PrivateChannel("server.{$this->deployment->site->server_id}"),
        ];
    }

    /**
     * Get the data to broadcast.
     * Only send minimal data - frontend will use partial reload to get full data.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'deployment_id' => $this->deployment->id,
            'deployment_ulid' => $this->deployment->ulid,
            'status' => $this->deployment->status->value,
            'event' => $this->event,
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'deployment.status.changed';
    }
}
