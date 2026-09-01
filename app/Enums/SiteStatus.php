<?php

namespace App\Enums;

enum SiteStatus: string
{
    case Up = 'up';
    case Down = 'down';
    case Unknown = 'unknown';

    /**
     * Get the label shown to the client.
     */
    public function label(): string
    {
        return match ($this) {
            self::Up => 'Up',
            self::Down => 'Down',
            self::Unknown => 'Not checked yet',
        };
    }

    /**
     * Get the palette tone for pills and dots.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Up => 'brand',
            self::Down => 'warm',
            self::Unknown => 'muted',
        };
    }
}
