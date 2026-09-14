<?php

namespace App\Actions\Billing;

use App\Models\Invoice;
use App\Models\RecurringInvoice;
use App\Models\StudioSetting;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;

/**
 * Raises the monthly invoice for every standing arrangement whose billing day
 * has come round.
 *
 * Runs daily rather than on the first of the month, because each arrangement
 * bills on its own day. Idempotency is the invoice's recurring_invoice_id, not
 * the date: once a schedule has an invoice in a month it is done for that
 * month however many times this runs.
 */
class RaiseRecurringInvoices
{
    public function __construct(private StudioSetting $settings) {}

    /**
     * Get the schedules still owing an invoice for the given month.
     *
     * @return Collection<int, RecurringInvoice>
     */
    public function due(?CarbonInterface $on = null): Collection
    {
        $on ??= Date::now();

        return RecurringInvoice::query()
            ->with('team')
            ->running($on)
            ->whereDoesntHave('invoices', fn ($query) => $query
                ->whereBetween('issued_on', [$on->copy()->startOfMonth(), $on->copy()->endOfMonth()]))
            ->get()
            ->filter(fn (RecurringInvoice $schedule) => $schedule->amount > 0 && $schedule->isDueBy($on))
            ->values();
    }

    /**
     * Raise the invoice for every arrangement that is due one.
     *
     * @return Collection<int, Invoice>
     */
    public function handle(?CarbonInterface $on = null): Collection
    {
        $on ??= Date::now();

        return $this->due($on)
            ->map(fn (RecurringInvoice $schedule) => $this->raiseFor($schedule, $on))
            ->filter()
            ->values();
    }

    /**
     * Raise one arrangement's invoice, unless it has already had one this
     * month or its day has not come round yet.
     */
    public function raiseFor(RecurringInvoice $schedule, ?CarbonInterface $on = null): ?Invoice
    {
        $on ??= Date::now();

        if (! $this->due($on)->contains(fn (RecurringInvoice $candidate) => $candidate->is($schedule))) {
            return null;
        }

        $raise = new RaiseInvoice($this->settings);

        // Work somebody else bills is written straight down as a settled
        // record. There is nothing to send and nobody to chase.
        if ($schedule->record_only) {
            return $raise->record(
                team: $schedule->team,
                note: $schedule->noteFor($on),
                amount: $schedule->amount,
                issuedOn: $schedule->billingDateIn($on),
                poNumber: $schedule->po_number,
                recurring: $schedule,
            );
        }

        return $raise->handle(
            team: $schedule->team,
            type: $schedule->type,
            note: $schedule->noteFor($on),
            amount: $schedule->amount,
            discount: $schedule->discount,
            poNumber: $schedule->po_number,
            recurring: $schedule,
        );
    }
}
