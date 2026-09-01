<?php

namespace App\Support;

use App\Enums\Currency;
use Illuminate\Support\Collection;

/**
 * Helpers for money that may not all be in the same currency.
 *
 * The studio bills each client in that client's own currency and holds no
 * exchange rates, so anything added up across clients has to stay honest about
 * the fact that it is more than one pot of money.
 */
class Money
{
    /**
     * Add up rows that may span currencies and format the result, e.g.
     * "£4,200" on its own, or "£4,200 + $1,800 USD" when they are mixed.
     *
     * @template TRow
     *
     * @param  Collection<int, TRow>  $rows
     * @param  callable(TRow): float  $amount
     * @param  callable(TRow): Currency  $currency
     */
    public static function total(Collection $rows, callable $amount, callable $currency): string
    {
        $totals = $rows
            ->groupBy(fn ($row) => $currency($row)->value)
            ->map(fn (Collection $group) => (float) $group->sum($amount))
            ->filter(fn (float $sum) => round($sum) != 0.0)
            ->sortDesc();

        if ($totals->isEmpty()) {
            return Currency::Base->format(0);
        }

        return $totals
            ->map(fn (float $sum, string $code) => Currency::from($code)->format(round($sum)))
            ->join(' + ');
    }
}
