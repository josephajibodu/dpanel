<?php

namespace App\Notifications;

use App\Models\SiteDomain;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SslCertificateRequestFailed extends Notification
{
    use Queueable;

    public function __construct(
        public SiteDomain $siteDomain,
        public string $errorMessage,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $hostname = $this->siteDomain->hostname;
        $site = $this->siteDomain->site;
        $server = $site->server;

        return (new MailMessage)
            ->subject("SSL certificate request failed: {$hostname}")
            ->line("Flitops couldn't issue or renew the SSL certificate for **{$hostname}** on **{$server->name}**.")
            ->line("**Error:** {$this->errorMessage}")
            ->action('View Domains', route('servers.sites.domains.index', [$server->team, $server, $site]))
            ->line("Check that the domain's DNS points at the server, then use **Renew SSL** to try again.");
    }
}
