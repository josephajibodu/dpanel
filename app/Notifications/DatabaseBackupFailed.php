<?php

namespace App\Notifications;

use App\Models\Backup;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DatabaseBackupFailed extends Notification
{
    use Queueable;

    public function __construct(
        public Backup $backup,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $server = $this->backup->targetServer();
        $targetName = $this->backup->targetName();

        return (new MailMessage)
            ->subject("Database backup failed: {$targetName}")
            ->line("A backup of **{$targetName}** on **{$server->name}** failed.")
            ->line("**Error:** {$this->backup->error_message}")
            ->action('View Backups', $this->backup->indexUrl())
            ->line('Check the database and try running the backup again.');
    }
}
