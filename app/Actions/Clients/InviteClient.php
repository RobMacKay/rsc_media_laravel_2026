<?php

namespace App\Actions\Clients;

use App\Enums\ClientAccess;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Notifications\Clients\PortalInvitation;
use Illuminate\Support\Facades\Notification;

/**
 * The single place the studio invites a client into the portal.
 *
 * Deliberately an invitation rather than App\Actions\Clients\CreateClient:
 * that opens the account outright and suits a client the studio is taking on,
 * whereas these businesses already exist — they came over from Invoice Ninja
 * with their history and no people. Nothing is created here until the client
 * accepts, so an invitation nobody answers leaves no half-made account behind.
 */
class InviteClient
{
    /**
     * How long a client has to act on an invitation.
     *
     * Longer than the three days a colleague gets from the team screen: a
     * client was not expecting this and may not read email every day.
     */
    public const VALID_FOR_DAYS = 14;

    /**
     * Invite one person into a client's portal.
     *
     * Access defaults to Full because the point of inviting a client is that
     * they can see their invoices, and that is the only level which does.
     */
    public function handle(
        Team $team,
        string $email,
        User $invitedBy,
        ClientAccess $access = ClientAccess::Full,
    ): TeamInvitation {
        $invitation = $team->invitations()->create([
            'email' => $email,
            // Not Owner: joinInvitedTeam records everyone who registers from an
            // invitation as a member, so promising otherwise here would be a
            // lie the acceptance path does not keep.
            'role' => TeamRole::Member,
            'access' => $access,
            'invited_by' => $invitedBy->id,
            'expires_at' => now()->addDays(self::VALID_FOR_DAYS),
        ]);

        Notification::route('mail', $email)->notify(new PortalInvitation($invitation));

        return $invitation;
    }
}
