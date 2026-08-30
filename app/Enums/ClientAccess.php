<?php

namespace App\Enums;

enum ClientAccess: string
{
    case Full = 'full';
    case Tickets = 'tickets';
    case View = 'view';

    /**
     * Get the display label for the access level.
     */
    public function label(): string
    {
        return match ($this) {
            self::Full => 'Full access',
            self::Tickets => 'Tickets only',
            self::View => 'View only',
        };
    }

    /**
     * Get the explanation shown beneath the access picker when inviting someone.
     */
    public function hint(): string
    {
        return match ($this) {
            self::Full => 'Can raise tickets, see invoices and add other people.',
            self::Tickets => 'Can raise and follow tickets. No billing, no team changes.',
            self::View => 'Can read project status and updates. Cannot raise tickets.',
        };
    }

    /**
     * Determine whether this level may see invoices and the plan billing strip.
     */
    public function canSeeBilling(): bool
    {
        return $this === self::Full;
    }

    /**
     * Determine whether this level may raise tickets.
     */
    public function canRaiseTickets(): bool
    {
        return $this !== self::View;
    }

    /**
     * Determine whether this level may invite and remove team members.
     */
    public function canManageTeam(): bool
    {
        return $this === self::Full;
    }
}
