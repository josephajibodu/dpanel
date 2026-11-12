<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Sent once, when a new account confirms its email address. It is queued so
 * a mail server problem can't break the page that confirms the address.
 */
class Welcome extends Notification implements ShouldQueue
{
    use Queueable;

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * @param  User  $notifiable
     */
    public function toMail(object $notifiable): MailMessage
    {
        $team = $notifiable->currentTeam;

        return (new MailMessage)
            ->subject('Welcome to Flitops')
            ->markdown('mail.welcome', [
                'firstName' => Str::before(trim($notifiable->name), ' '),
                'steps' => self::steps(),
                'actionUrl' => $team ? route('servers.create', $team) : url('dashboard'),
            ]);
    }

    /**
     * What a new account can do, in the order most people do it. Only things
     * every plan includes are listed.
     *
     * @return list<array{title: string, text: string}>
     */
    public static function steps(): array
    {
        return [
            [
                'title' => 'Create or connect a server',
                'text' => 'Provision on DigitalOcean, Hetzner, Vultr or AWS, or connect any Ubuntu server you already run over SSH.',
            ],
            [
                'title' => 'Deploy from Git',
                'text' => 'Connect GitHub, GitLab or Bitbucket and deploy on every push, with zero-downtime releases and one-click rollback.',
            ],
            [
                'title' => 'Add domains with free SSL',
                'text' => "Every domain gets a free Let's Encrypt certificate, issued once its DNS is verified and renewed for you.",
            ],
            [
                'title' => 'Create databases',
                'text' => 'Set up databases and users with scoped permissions, or import an existing dump.',
            ],
            [
                'title' => 'Run workers and scheduled jobs',
                'text' => 'Keep queue workers, Horizon or any long-running process alive, and manage cron jobs without touching a crontab.',
            ],
            [
                'title' => 'Manage it all from the browser',
                'text' => 'Edit your .env, read logs, run one-off commands and open a terminal on any server.',
            ],
        ];
    }
}
