<?php

namespace App\Notifications;

use App\Models\Enquiry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NewEnquiry extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(public Enquiry $enquiry)
    {
        //
    }

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
            ->subject(__('New enquiry from :name', ['name' => $this->enquiry->name]))
            ->replyTo($this->enquiry->email, $this->enquiry->name)
            ->line(__(':name at :company got in touch about ":topic".', [
                'name' => $this->enquiry->name,
                'company' => $this->enquiry->company ?: __('no company given'),
                'topic' => $this->enquiry->topicLabel(),
            ]))
            ->line($this->enquiry->message)
            ->line(__('Reply to: :email', ['email' => $this->enquiry->email]));
    }
}
