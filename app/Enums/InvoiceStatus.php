<?php

namespace App\Enums;

enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Overdue = 'overdue';
    case Paid = 'paid';

    /**
     * Get the display label for the status.
     */
    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * Get the palette token used to tint pills for this status.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Draft => 'muted',
            self::Sent => 'soft',
            self::Overdue => 'warm',
            self::Paid => 'brand',
        };
    }

    /**
     * Determine whether the invoice still owes the studio money.
     */
    public function isOutstanding(): bool
    {
        return $this !== self::Paid;
    }
}
