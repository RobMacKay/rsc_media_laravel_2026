<?php

namespace App\Enums;

enum InvoiceType: string
{
    case Deposit = 'deposit';
    case Final = 'final';
    case Plan = 'plan';
    case AdHoc = 'ad_hoc';

    /**
     * Get the display label for the type.
     */
    public function label(): string
    {
        return match ($this) {
            self::AdHoc => 'Ad hoc',
            default => ucfirst($this->value),
        };
    }
}
