<?php

namespace App\Concerns;

/**
 * Routes a queued broadcast event onto the dedicated `broadcasts` queue, so a
 * Reverb outage only fails the broadcast job — never the provisioning, deploy
 * or delete flow that fired the event — and UI updates never wait behind
 * long-running jobs on the other queues.
 */
trait QueuesBroadcasts
{
    public function broadcastQueue(): string
    {
        return 'broadcasts';
    }
}
