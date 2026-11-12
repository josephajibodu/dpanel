<?php

namespace App\Notifications;

use App\Models\Site;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SiteWebServerSyncFailed extends Notification
{
    use Queueable;

    public function __construct(
        public Site $site,
        public string $errorMessage,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $server = $this->site->server;
        $webServer = $server->webServer()->label();

        return (new MailMessage)
            ->error()
            ->subject("Web server config not applied: {$this->site->domain}")
            ->line("Flitops couldn't apply a change to the {$webServer} config for **{$this->site->domain}** on **{$server->name}**, so the change is not live.")
            ->line("**Error:** {$this->errorMessage}")
            ->action('View Web Server Config', route('servers.sites.nginx.index', [$server->team, $server, $this->site]))
            ->line('Once the cause is fixed, make the change again to retry.');
    }
}
