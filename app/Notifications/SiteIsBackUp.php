<?php

namespace App\Notifications;

use App\Models\Site;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SiteIsBackUp extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(public Site $site) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__(':site is back up', ['site' => $this->site->name]))
            ->greeting(__(':site is answering again.', ['site' => $this->site->name]))
            ->line(__('It was unreachable from :when.', [
                'when' => $this->site->last_down_at?->diffForHumans() ?? __('a short while ago'),
            ]))
            ->action(__('Open your site health'), route('client.health'));
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'site_id' => $this->site->id,
            'host' => $this->site->host,
        ];
    }
}
