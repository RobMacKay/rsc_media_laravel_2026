<?php

namespace App\Enums;

enum ProposalStatus: string
{
    case Submitted = 'submitted';
    case Sent = 'sent';
    case Approved = 'approved';
    case Declined = 'declined';

    /**
     * Get the label the studio sees.
     */
    public function label(): string
    {
        return match ($this) {
            self::Submitted => 'Waiting to be written up',
            self::Sent => 'With the client',
            self::Approved => 'Signed off',
            self::Declined => 'Declined',
        };
    }

    /**
     * Get the label the client sees, which is about what they need to do next.
     */
    public function clientLabel(): string
    {
        return match ($this) {
            self::Submitted => 'With RSC',
            self::Sent => 'Needs your sign-off',
            self::Approved => 'Signed off',
            self::Declined => 'Not going ahead',
        };
    }

    /**
     * Get the palette token used to tint this status.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Submitted, self::Sent => 'warm',
            self::Approved => 'brand',
            self::Declined => 'muted',
        };
    }
}
