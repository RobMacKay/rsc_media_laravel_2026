<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/**
 * The welcome email for an account the studio opened on someone's behalf.
 *
 * It carries a signed link rather than a password reset token, so the studio
 * can give someone a week to get around to it without loosening how long a
 * real password reset stays valid.
 */
class SetYourPassword extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * How long the welcome link stays good for.
     */
    public const VALID_FOR_DAYS = 7;

    /**
     * Create a new notification instance.
     */
    public function __construct(public ?string $invitedBy = null) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(User $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(User $notifiable): MailMessage
    {
        $team = $notifiable->currentTeam;

        return (new MailMessage)
            ->subject(__('Your RSC Media client area'))
            ->greeting(__('Hello :name,', ['name' => str($notifiable->name)->before(' ')]))
            ->line($this->invitedBy
                ? __(':who has set up a client area for :business.', ['who' => $this->invitedBy, 'business' => $team?->name])
                : __('We have set up a client area for :business.', ['business' => $team?->name]))
            ->line(__('Projects, tickets, invoices and the health of your sites, all in one place. Choose a password and it is yours.'))
            ->action(__('Set your password'), $this->link($notifiable))
            ->line(__('The link works for :days days. If it runs out, use "forgotten?" on the sign-in page and we will send another.', [
                'days' => self::VALID_FOR_DAYS,
            ]));
    }

    /**
     * Build the signed link that lets this person set their first password.
     */
    private function link(User $notifiable): string
    {
        return URL::temporarySignedRoute(
            'password.set',
            now()->addDays(self::VALID_FOR_DAYS),
            ['user' => $notifiable->getKey()],
        );
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(User $notifiable): array
    {
        return ['user_id' => $notifiable->getKey()];
    }
}
