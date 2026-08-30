<?php

namespace App\Enums;

enum TicketPriority: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
    case Urgent = 'urgent';

    /**
     * Get the display label for the priority.
     */
    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * Get the guidance shown under the priority picker when raising a ticket.
     */
    public function hint(): string
    {
        return match ($this) {
            self::Low => 'Nice to have. Picked up within the next couple of weeks.',
            self::Normal => 'The default. Looked at within a working day.',
            self::High => 'Something is broken but you can still work. Same day where possible.',
            self::Urgent => 'You are stopped. Ring or WhatsApp as well so I see it straight away.',
        };
    }

    /**
     * Determine whether this priority should be highlighted in warm ink.
     */
    public function isPressing(): bool
    {
        return in_array($this, [self::High, self::Urgent], true);
    }
}
