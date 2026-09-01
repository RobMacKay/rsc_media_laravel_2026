<?php

namespace App\Actions\Billing;

use App\Enums\InvoiceType;
use App\Models\Invoice;
use App\Models\StudioSetting;
use App\Models\Team;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Raises the monthly retainer invoice for every client on a support plan.
 *
 * Runs on the first of the month from the scheduler, and on demand from the
 * admin invoices screen. Both routes come through here, so raising them early
 * by hand and letting the schedule run cannot produce two invoices for the
 * same client and month.
 */
class RaisePlanInvoices
{
    public function __construct(private StudioSetting $settings) {}

    /**
     * Get the clients still owing a plan invoice for the given month.
     *
     * @return Collection<int, Team>
     */
    public function due(?CarbonInterface $month = null): Collection
    {
        $month ??= now();

        return Team::query()
            ->with('plan')
            ->whereNotNull('plan_id')
            ->whereDoesntHave('invoices', fn ($query) => $query
                ->where('type', InvoiceType::Plan)
                ->whereBetween('issued_on', [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()]))
            ->get()
            ->filter(fn (Team $team) => $team->plan->price > 0)
            ->values();
    }

    /**
     * Raise the plan invoice for every client that is due one.
     *
     * @return Collection<int, Invoice>
     */
    public function handle(?CarbonInterface $month = null): Collection
    {
        $month ??= now();

        return $this->due($month)->map(fn (Team $team) => $this->raiseFor($team, $month))->filter()->values();
    }

    /**
     * Raise one client's plan invoice, unless they have already had one for
     * that month.
     */
    public function raiseFor(Team $team, ?CarbonInterface $month = null): ?Invoice
    {
        $month ??= now();

        $stillDue = $this->due($month)->contains(fn (Team $candidate) => $candidate->is($team));

        if (! $stillDue) {
            return null;
        }

        return (new RaiseInvoice($this->settings))->handle(
            team: $team,
            type: InvoiceType::Plan,
            note: $team->plan->name.' — '.$month->format('F'),
            amount: $team->plan->price,
        );
    }
}
