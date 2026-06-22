<?php

namespace App\Notifications;

use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ServerProvisioningFailed extends Notification
{
    use Queueable;

    public function __construct(
        public Server $server,
        public string $errorMessage,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $serverUrl = route('servers.show', [$this->server->team, $this->server]);

        return (new MailMessage)
            ->subject("Server provisioning failed: {$this->server->name}")
            ->line("Your server **{$this->server->name}** failed to provision.")
            ->line("**Error:** {$this->errorMessage}")
            ->action('View Server', $serverUrl)
            ->line('You may delete the server and try again, or contact support if the issue persists.');
    }
}
