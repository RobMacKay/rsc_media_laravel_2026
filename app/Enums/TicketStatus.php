<?php

namespace App\Enums;

enum TicketStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case QuoteSent = 'quote_sent';
    case WaitingOnClient = 'waiting_on_client';
    case Resolved = 'resolved';

    /**
     * Get the label shown to the studio in the admin queue.
     */
    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::InProgress => 'In progress',
            self::QuoteSent => 'Quote sent',
            self::WaitingOnClient => 'Waiting on client',
            self::Resolved => 'Resolved',
        };
    }

    /**
     * Get the label shown to the client, who reads "waiting on client" as being about them.
     */
    public function clientLabel(): string
    {
        return $this === self::WaitingOnClient ? 'Waiting on you' : $this->label();
    }

    /**
     * Get the palette token used to tint pills for this status.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Open => 'brand',
            self::InProgress, self::QuoteSent => 'soft',
            self::WaitingOnClient => 'warm',
            self::Resolved => 'muted',
        };
    }

    /**
     * Determine whether a ticket in this status still needs work.
     */
    public function isOpen(): bool
    {
        return $this !== self::Resolved;
    }
}
