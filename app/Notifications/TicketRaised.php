<?php

namespace App\Notifications;

use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TicketRaised extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(public Ticket $ticket) {}

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
        $ticket = $this->ticket;

        $message = (new MailMessage)
            ->subject(__('[:priority] :reference — :title', [
                'priority' => str($ticket->priority->label())->upper(),
                'reference' => $ticket->reference,
                'title' => $ticket->title,
            ]))
            ->greeting(__(':client raised a ticket.', ['client' => $ticket->team->name]))
            ->line(__(':reference · :type · :priority priority', [
                'reference' => $ticket->reference,
                'type' => $ticket->type->label(),
                'priority' => str($ticket->priority->label())->lower(),
            ]))
            ->line('**'.$ticket->title.'**')
            ->line($ticket->description);

        if ($ticket->system) {
            $message->line(__('System: :system', ['system' => $ticket->system]));
        }

        if ($ticket->page_url) {
            $message->line(__('Page: :url', ['url' => $ticket->page_url]));
        }

        return $message
            ->line(__('Reported by :who.', ['who' => $ticket->reporter?->name ?: __('someone at the client')]))
            ->action(__('Open the ticket'), route('admin.queue', ['ticket' => $ticket->reference]));
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'ticket_id' => $this->ticket->id,
            'reference' => $this->ticket->reference,
            'priority' => $this->ticket->priority->value,
        ];
    }
}
