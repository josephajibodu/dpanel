<?php

namespace App\Events;

use App\Concerns\QueuesBroadcasts;
use App\Models\Server;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ServerSitesUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, QueuesBroadcasts, SerializesModels;

    public function __construct(
        public Server $server,
    ) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('server.'.$this->server->id),
        ];
    }

    /**
     * Broadcast only a change signal; listeners reload the data they need, so
     * the payload stays under Reverb's request size limit however many sites
     * the server has.
     *
     * @return array{server_id: int}
     */
    public function broadcastWith(): array
    {
        return [
            'server_id' => $this->server->id,
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'server.sites.updated';
    }
}
