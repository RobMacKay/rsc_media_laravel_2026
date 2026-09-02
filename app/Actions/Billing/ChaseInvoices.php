<?php

namespace App\Actions\Billing;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\StudioSetting;
use App\Notifications\InvoiceReminderNotification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Keeps unpaid invoices honest: marks the ones that have gone past their date,
 * and sends the reminder that is due, at most one per invoice per stage.
 */
class ChaseInvoices
{
    public function __construct(private StudioSetting $settings) {}

    /**
     * Move every outstanding invoice past its due date onto Overdue.
     *
     * @return Collection<int, Invoice>
     */
    public function markOverdue(): Collection
    {
        $invoices = Invoice::query()
            ->overdue()
            ->where('status', InvoiceStatus::Sent)
            ->get();

        $invoices->each->update(['status' => InvoiceStatus::Overdue]);

        return $invoices;
    }

    /**
     * Send whichever reminder is now due on each unpaid invoice.
     *
     * @return Collection<int, Invoice>
     */
    public function sendReminders(): Collection
    {
        if (! $this->settings->invoice_reminders) {
            return new Collection;
        }

        $reminded = Invoice::query()
            ->whereNotIn('status', [InvoiceStatus::Paid, InvoiceStatus::Draft])
            ->whereNull('reminders_paused_at')
            ->with(['team.members'])
            ->get()
            ->filter(function (Invoice $invoice) {
                $stage = $invoice->reminderDue();

                if ($stage === null) {
                    return false;
                }

                $recipients = $this->recipientsFor($invoice);

                if ($recipients === []) {
                    return false;
                }

                Notification::route('mail', $recipients)
                    ->notify(new InvoiceReminderNotification($invoice, $stage));

                $invoice->update([
                    'reminder_stage' => $stage,
                    'last_reminded_at' => now(),
                ]);

                return true;
            });

        return new Collection($reminded->values()->all());
    }

    /**
     * Get who should hear about an unpaid invoice.
     *
     * The billing address the client gave us if there is one, otherwise the
     * people on the account who are allowed to see invoices at all. Never
     * everyone: a tickets-only member has no business being chased for money.
     *
     * @return array<int, string>
     */
    private function recipientsFor(Invoice $invoice): array
    {
        if (filled($invoice->team->billing_email)) {
            return [$invoice->team->billing_email];
        }

        return $invoice->team->members
            ->filter(fn ($member) => $member->accessFor($invoice->team)->canSeeBilling())
            ->pluck('email')
            ->all();
    }
}
