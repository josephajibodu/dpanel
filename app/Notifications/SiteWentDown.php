<?php

namespace App\Notifications;

use App\Models\SiteIncident;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SiteWentDown extends Notification
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

        return (new MailMessage)
            ->subject("Site down: {$site->domain}")
            ->line("**{$site->domain}** on **{$server->name}** is not responding.")
            ->line("**Reason:** {$this->incident->reason}")
            ->action('View Site', route('servers.sites.show', [$server->team, $server, $site]))
            ->line("You'll get another email when it's back up.");
    }
}
