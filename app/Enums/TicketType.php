<?php

namespace App\Enums;

enum TicketType: string
{
    case Bug = 'bug';
    case Change = 'change';
    case Question = 'question';

    /**
     * Get the display label for the type.
     */
    public function label(): string
    {
        return ucfirst($this->value);
    }
}
