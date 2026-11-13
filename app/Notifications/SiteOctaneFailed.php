<?php

namespace App\Notifications;

use App\Models\Site;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SiteOctaneFailed extends Notification
{
    use Queueable;

    /**
     * @param  bool  $cleanupFinished  Whether Flitops managed to remove the Octane process after the failure.
     */
    public function __construct(
        public Site $site,
        public string $errorMessage,
        public bool $cleanupFinished = true,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $server = $this->site->server;

        $message = (new MailMessage)
            ->error()
            ->subject("Octane could not be switched on: {$this->site->domain}")
            ->line("Flitops couldn't start Laravel Octane for **{$this->site->domain}** on **{$server->name}**. The site is still served the normal way (PHP-FPM), so visitors are not affected.")
            ->line("**Error:** {$this->errorMessage}");

        if (! $this->cleanupFinished) {
            $message->line("The server stopped responding before Flitops could remove the Octane process, so it may still be on the server. Once the server is reachable, save the site's settings and Flitops will remove it.");
        }

        return $message
            ->action('View Site Settings', route('servers.sites.edit', [$server->team, $server, $this->site]))
            ->line('Once the cause is fixed, switch Octane on again.');
    }
}
