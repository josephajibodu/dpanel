<?php

namespace App\Notifications;

use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class WildcardCertificateExpiring extends Notification
{
    use Queueable;

    /**
     * @param  string|null  $staleHostname  Set when a server is still serving an older copy than
     *                                      the renewed certificate on the Flitops host.
     */
    public function __construct(
        public string $domain,
        public CarbonInterface $expiresAt,
        public ?string $staleHostname = null,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $expiryDate = $this->expiresAt->toFormattedDayDateString();

        $message = $this->expiresAt->isPast()
            ? (new MailMessage)
                ->subject("Wildcard certificate expired: {$this->domain}")
                ->line("The wildcard certificate `{$this->domain}` expired on {$expiryDate}. Free-domain sites get a security warning until it's replaced.")
            : (new MailMessage)
                ->subject("Wildcard certificate expiring soon: {$this->domain}")
                ->line("The wildcard certificate `{$this->domain}` expires in {$this->daysLeft()}, on {$expiryDate}. It should have been renewed by now.");

        if ($this->staleHostname !== null) {
            return $message->line("The Flitops host has a renewed certificate, but **{$this->staleHostname}** is still serving this older copy, so the renewed certificate didn't reach its server (check SSH access and `nginx -t` there).");
        }

        return $message->line('Renewal runs daily on the Flitops host. Check that the scheduler and queue worker are running, then run `php artisan flitops:wildcard:issue` to see why it fails.');
    }

    private function daysLeft(): string
    {
        $days = (int) ceil(now()->diffInDays($this->expiresAt));

        return $days.' '.Str::plural('day', $days);
    }
}
