<?php

namespace App\Events;

use App\Concerns\QueuesBroadcasts;
use App\Models\Server;
use App\Models\Site;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ServerSitesUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, QueuesBroadcasts, SerializesModels;

    /**
     * Pass the site when the change is about one site, so that site's own
     * pages hear about it without listening to every site on the server.
     */
    public function __construct(
        public Server $server,
        public ?Site $site = null,
    ) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return array_values(array_filter([
            new PrivateChannel('server.'.$this->server->id),
            $this->site ? new PrivateChannel('site.'.$this->site->id) : null,
        ]));
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
