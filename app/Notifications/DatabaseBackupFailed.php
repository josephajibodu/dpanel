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
        $serverDatabase = $this->backup->serverDatabase;
        $server = $serverDatabase->server;
        $backupsUrl = route('servers.databases.backups.index', [$server->team, $server, $serverDatabase]);

        return (new MailMessage)
            ->subject("Database backup failed: {$serverDatabase->name}")
            ->line("A backup of **{$serverDatabase->name}** on **{$server->name}** failed.")
            ->line("**Error:** {$this->backup->error_message}")
            ->action('View Backups', $backupsUrl)
            ->line('Check the database and try running the backup again.');
    }
}
