<?php

namespace App\Enums;

enum ContactPreference: string
{
    case Email = 'email';
    case Phone = 'phone';
    case WhatsApp = 'whatsapp';

    /**
     * Get the label shown on the onboarding and profile screens.
     */
    public function label(): string
    {
        return match ($this) {
            self::Email => 'Email',
            self::Phone => 'Phone',
            self::WhatsApp => 'WhatsApp',
        };
    }
}
