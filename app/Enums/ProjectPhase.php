<?php

namespace App\Enums;

enum ProjectPhase: string
{
    case Scoping = 'scoping';
    case Build = 'build';
    case Testing = 'testing';
    case Live = 'live';

    /**
     * Get the display label for the phase.
     */
    public function label(): string
    {
        return ucfirst($this->value);
    }
}
