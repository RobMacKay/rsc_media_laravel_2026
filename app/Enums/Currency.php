<?php

namespace App\Enums;

enum Currency: string
{
    case GBP = 'GBP';
    case EUR = 'EUR';
    case USD = 'USD';
    case CAD = 'CAD';

    /**
     * The studio's own currency, which its default rates and plan prices are
     * quoted in, and the fallback when there is nothing to add up.
     */
    public const Base = self::GBP;

    /**
     * Get the symbol shown in front of an amount.
     */
    public function symbol(): string
    {
        return match ($this) {
            self::GBP => '£',
            self::EUR => '€',
            self::USD, self::CAD => '$',
        };
    }

    /**
     * Get the label for pickers, which spells out the two dollars.
     */
    public function label(): string
    {
        return match ($this) {
            self::GBP => 'GBP £',
            self::EUR => 'EUR €',
            self::USD => 'USD $',
            self::CAD => 'CAD $',
        };
    }

    /**
     * Get the code shown after an amount.
     *
     * Dollars are ambiguous on their own, so USD and CAD carry their code.
     */
    public function suffix(): string
    {
        return match ($this) {
            self::USD, self::CAD => ' '.$this->value,
            default => '',
        };
    }

    /**
     * Format an amount in this currency.
     */
    public function format(float $amount, int $decimals = 0): string
    {
        return $this->symbol().number_format($amount, $decimals).$this->suffix();
    }
}
