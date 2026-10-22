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
        return ['mail', 'database'];
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

    /**
     * Stored for the alert banner, which shows it on every page until it is
     * dismissed or a later backup of the same target succeeds.
     *
     * @return array{title: string, message: ?string, url: string, target: string}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => "Backup of {$this->backup->targetName()} on {$this->backup->targetServer()->name} failed",
            'message' => $this->backup->error_message,
            'url' => $this->backup->indexUrl(),
            'target' => self::targetKey($this->backup),
        ];
    }

    /**
     * Identifies what was being backed up, so a later success can clear the alert.
     */
    public static function targetKey(Backup $backup): string
    {
        return $backup->site_id
            ? "site:{$backup->site_id}"
            : "server_database:{$backup->server_database_id}";
    }
}
