<?php

namespace App\Events;

use App\Concerns\QueuesBroadcasts;
use App\Enums\ProvisioningStep;
use App\Enums\ServerStatus;
use App\Models\Server;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ServerStatusChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, QueuesBroadcasts, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public Server $server,
        public ServerStatus $previousStatus,
        public ?ProvisioningStep $previousProvisioningStep = null,
    ) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('servers.'.$this->server->team_id),
            new PrivateChannel('server.'.$this->server->id),
        ];
    }

    /**
     * Broadcast only the change; pages reload the server they show, so the
     * payload stays small however many sites and databases the server has.
     *
     * @return array{server_id: int, previous_status: string, current_status: string, previous_provisioning_step: int|null, current_provisioning_step: int|null}
     */
    public function broadcastWith(): array
    {
        return [
            'server_id' => $this->server->id,
            'previous_status' => $this->previousStatus->value,
            'current_status' => $this->server->status->value,
            'previous_provisioning_step' => $this->previousProvisioningStep?->value,
            'current_provisioning_step' => $this->server->provisioning_step?->value,
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'server.status.changed';
    }
}
