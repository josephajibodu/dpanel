<?php

namespace App\Notifications;

use App\Models\SiteIncident;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SiteRecovered extends Notification
{
    use Queueable;

    public function __construct(
        public SiteIncident $incident,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $site = $this->incident->site;
        $server = $site->server;
        $downtime = $this->incident->started_at->diffForHumans($this->incident->resolved_at, true);

        return (new MailMessage)
            ->subject("Site recovered: {$site->domain}")
            ->line("**{$site->domain}** on **{$server->name}** is responding again.")
            ->line("It was down for {$downtime}.")
            ->action('View Site', route('servers.sites.show', [$server->team, $server, $site]));
    }
}
