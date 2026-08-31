<?php

namespace App\Enums;

enum QuoteResponse: string
{
    case Approved = 'approved';
    case Declined = 'declined';

    /**
     * Get the display label for the response.
     */
    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * Get the palette token used to tint the response.
     */
    public function tone(): string
    {
        return $this === self::Approved ? 'brand' : 'warm';
    }
}
