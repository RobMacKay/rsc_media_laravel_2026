<?php

namespace App\Notifications;

use App\Enums\InvoiceReminder;
use App\Models\Invoice;
use App\Models\StudioSetting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InvoiceReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(public Invoice $invoice, public InvoiceReminder $stage) {}

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
        $settings = StudioSetting::current();
        $money = $this->invoice->money($this->invoice->total());

        $message = (new MailMessage)
            ->subject($this->stage->subject($this->invoice->number))
            ->greeting($this->stage->opening())
            ->line(__(':number for :amount, due :date.', [
                'number' => $this->invoice->number,
                'amount' => $money,
                'date' => $this->invoice->due_on->format('j F'),
            ]));

        if ($this->invoice->note) {
            $message->line(__('For: :note', ['note' => $this->invoice->note]));
        }

        $message->line(__('Please quote :reference when you pay.', [
            'reference' => $this->invoice->paymentReference($settings),
        ]));

        return $message
            ->action(__('View the invoice'), route('client.invoices.show', $this->invoice->number))
            ->line($this->stage->closing());
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'invoice_id' => $this->invoice->id,
            'number' => $this->invoice->number,
            'stage' => $this->stage->value,
        ];
    }
}
