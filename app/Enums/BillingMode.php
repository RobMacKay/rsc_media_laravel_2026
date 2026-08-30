<?php

namespace App\Enums;

enum BillingMode: string
{
    case SupportHours = 'support_hours';
    case Chargeable = 'chargeable';
    case NoCharge = 'no_charge';

    /**
     * Get the display label for the billing mode.
     */
    public function label(): string
    {
        return match ($this) {
            self::SupportHours => 'Within support hours',
            self::Chargeable => 'Chargeable',
            self::NoCharge => 'No charge',
        };
    }

    /**
     * Get the explanation shown beneath the billing picker.
     */
    public function hint(): string
    {
        return match ($this) {
            self::SupportHours => 'Comes off their monthly allowance. Client sees the hours, not a price.',
            self::Chargeable => 'Client gets the quote to approve before you start. Nothing runs until they accept.',
            self::NoCharge => 'Logged against the job for your own records. Client sees no cost at all.',
        };
    }
}
