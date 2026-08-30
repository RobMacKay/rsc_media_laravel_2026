<?php

namespace App\Enums;

enum UpdateKind: string
{
    case Project = 'project';
    case Studio = 'studio';

    /**
     * Get the palette token used to tint the update's tag.
     */
    public function tone(): string
    {
        return $this === self::Studio ? 'warm' : 'brand';
    }
}
