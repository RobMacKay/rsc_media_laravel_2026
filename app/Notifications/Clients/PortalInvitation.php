<?php

namespace App\Notifications\Clients;

use App\Actions\Clients\InviteClient;
use App\Models\StudioSetting;
use App\Models\TeamInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The studio asking a client to come into the portal for the first time.
 *
 * Deliberately not App\Notifications\Teams\TeamInvitation, which says "Kirsty
 * has invited you to join Braemar Joinery" and is right for a colleague being
 * added by someone they work with. This one goes to a client who has had
 * invoices by email for years and is not expecting it, so it says who it is
 * from and what is waiting for them.
 */
class PortalInvitation extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public TeamInvitation $invitation) {}

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
        $studio = StudioSetting::current();
        $business = $this->invitation->team->name;

        return (new MailMessage)
            ->subject(__('Your :studio account is ready to set up', ['studio' => $studio->company_name]))
            ->greeting(__('Hello'))
            ->line(__(
                'We have put everything for :business in one place — invoices, the work we have on, and anywhere we need something from you.',
                ['business' => $business],
            ))
            ->line(__('Your invoice history is already there, so you can look back over past work without going through old email.'))
            ->action(__('Set up your account'), route('login', ['invitation' => $this->invitation->code]))
            ->line(__('The link is for :email and is good for :days days.', [
                'email' => $this->invitation->email,
                // From now until it lapses, not the other way round: the other
                // order reads as "good for -13.99 days".
                'days' => $this->days(),
            ]))
            ->salutation(__('Thanks, :name', ['name' => $studio->company_name]));
    }

    /**
     * Get how many whole days are left to act on the invitation.
     */
    private function days(): int
    {
        $expires = $this->invitation->expires_at;

        return $expires === null
            ? InviteClient::VALID_FOR_DAYS
            : max((int) round(now()->diffInDays($expires)), 1);
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'invitation_id' => $this->invitation->id,
            'team_id' => $this->invitation->team_id,
            'team_name' => $this->invitation->team->name,
        ];
    }
}
