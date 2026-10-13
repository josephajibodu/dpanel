<?php

namespace App\Notifications;

use App\Models\SiteDomain;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class SslCertificateExpiring extends Notification
{
    use Queueable;

    public function __construct(
        public SiteDomain $siteDomain,
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
        $expiresAt = $this->siteDomain->ssl_expires_at;
        $expiryDate = $expiresAt->toFormattedDayDateString();

        $message = $expiresAt->isPast()
            ? (new MailMessage)
                ->subject("SSL certificate expired: {$hostname}")
                ->line("The SSL certificate for **{$hostname}** on **{$server->name}** expired on {$expiryDate}. Visitors get a security warning, and HTTPS requests to it, such as webhooks, are failing.")
            : (new MailMessage)
                ->subject("SSL certificate expiring soon: {$hostname}")
                ->line("The SSL certificate for **{$hostname}** on **{$server->name}** expires in {$this->daysLeft()}, on {$expiryDate}. It should have been renewed by now.");

        return $message
            ->action('View Domains', route('servers.sites.domains.index', [$server->team, $server, $site]))
            ->line('Use **Renew SSL** on the domain to issue a fresh certificate.');
    }

    private function daysLeft(): string
    {
        $days = (int) ceil(now()->diffInDays($this->siteDomain->ssl_expires_at));

        return $days.' '.Str::plural('day', $days);
    }
}
