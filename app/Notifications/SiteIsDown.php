<?php

namespace App\Notifications;

use App\Models\Site;
use App\Models\SiteCheck;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SiteIsDown extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(public Site $site, public SiteCheck $check) {}

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
        $message = (new MailMessage)
            ->subject(__(':site looks to be down', ['site' => $this->site->name]))
            ->greeting(__('We think :site is down.', ['site' => $this->site->name]))
            ->line(__('Our monitor could not reach :url on the last :count checks.', [
                'url' => $this->site->url,
                'count' => $this->site->consecutive_failures,
            ]));

        if ($this->check->http_status) {
            $message->line(__('It answered with :code.', ['code' => $this->check->http_status]));
        } elseif ($this->check->error) {
            $message->line(__('What we saw: :error', ['error' => $this->check->error]));
        }

        return $message
            ->action(__('Open your site health'), route('client.health'))
            ->line(__('We are on it. You will get one more email when it is back, and nothing in between.'));
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
            'http_status' => $this->check->http_status,
        ];
    }
}
