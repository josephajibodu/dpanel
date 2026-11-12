<?php

namespace App\Notifications;

use App\Models\SiteIncident;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\HtmlString;

class SiteWentDown extends Notification
{
    use Queueable;

    /**
     * @param  array<int, string>  $errorLogLines  The site's last few error log lines, if they could be read.
     */
    public function __construct(
        public SiteIncident $incident,
        public array $errorLogLines = [],
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

        $message = (new MailMessage)
            ->error()
            ->subject("Site down: {$site->domain}")
            ->line("**{$site->domain}** on **{$server->name}** is not responding.")
            ->line("**Reason:** {$this->incident->reason}");

        if ($this->errorLogLines !== []) {
            // Log lines hold request paths anyone can send, so they're
            // escaped and kept in a <pre> block that Markdown leaves alone.
            $message->line("**Recent {$server->webServer()->label()} errors:**")
                ->line(new HtmlString(
                    '<pre>'.e(implode("\n", $this->errorLogLines)).'</pre>'
                ));
        }

        return $message
            ->action('View Site', route('servers.sites.show', [$server->team, $server, $site]))
            ->line("You'll get another email when it's back up.");
    }
}
