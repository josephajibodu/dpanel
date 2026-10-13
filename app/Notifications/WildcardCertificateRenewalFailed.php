<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WildcardCertificateRenewalFailed extends Notification
{
    use Queueable;

    public function __construct(
        public string $domain,
        public string $errorMessage,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Wildcard certificate renewal failed: {$this->domain}")
            ->line("Flitops couldn't issue or renew the wildcard certificate `{$this->domain}`.")
            ->line("**Error:** {$this->errorMessage}")
            ->line("Any existing certificate keeps working until it expires, and you'll be reminded as that gets closer. Fix the error above, then run `php artisan flitops:wildcard:issue` to retry.");
    }
}
