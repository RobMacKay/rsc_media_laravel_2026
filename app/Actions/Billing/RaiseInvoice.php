<?php

namespace App\Actions\Billing;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\RecurringInvoice;
use App\Models\StudioSetting;
use App\Models\Team;
use App\Models\Ticket;
use Carbon\CarbonInterface;

/**
 * The single place an invoice is created, so the number, VAT rate and due date
 * are worked out the same way whether it came from a signed off proposal, a
 * finished project, a chargeable ticket or the one-off form.
 */
class RaiseInvoice
{
    public function __construct(private StudioSetting $settings) {}

    /**
     * Raise an invoice against a client.
     */
    public function handle(
        Team $team,
        InvoiceType $type,
        string $note,
        float $amount,
        ?Project $project = null,
        ?Ticket $ticket = null,
        InvoiceStatus $status = InvoiceStatus::Sent,
        float $discount = 0,
        ?string $poNumber = null,
        ?RecurringInvoice $recurring = null,
    ): Invoice {
        return Invoice::create([
            'number' => Invoice::nextNumber(),
            'team_id' => $team->id,
            'project_id' => $project?->id,
            'ticket_id' => $ticket?->id,
            'recurring_invoice_id' => $recurring?->id,
            'type' => $type,
            'note' => $note,
            'amount' => $amount,
            'discount' => $discount,
            'po_number' => $poNumber,
            'vat_rate' => $this->settings->effectiveVatRate(),
            'currency' => $team->currency,
            'issued_on' => now(),
            'due_on' => now()->addDays($team->effectivePaymentTerms($this->settings)),
            'status' => $status,
        ]);
    }

    /**
     * Raise the balance owed on a project once the work is done.
     */
    public function final(Project $project): Invoice
    {
        return $this->handle(
            team: $project->team,
            type: InvoiceType::Final,
            note: $project->title.' — balance on completion',
            amount: $project->balanceToInvoice(),
            project: $project,
        );
    }

    /**
     * Raise the invoice for a chargeable ticket the client has approved.
     */
    public function forTicket(Ticket $ticket): Invoice
    {
        return $this->handle(
            team: $ticket->team,
            type: InvoiceType::AdHoc,
            note: $ticket->reference.' — '.$ticket->title,
            amount: round($ticket->quoteTotal(), 2),
            project: $ticket->project,
            ticket: $ticket,
        );
    }

    /**
     * Record work that somebody else billed, such as an agency contract paid
     * against a remittance advice.
     *
     * It is settled the moment it is written, because the remittance is
     * notice that the money has already been paid. Nothing here is ever sent
     * to anyone: it exists so the studio has the figure and can hang the
     * remittance PDF off it.
     */
    public function record(
        Team $team,
        string $note,
        float $amount,
        ?CarbonInterface $issuedOn = null,
        ?string $externalReference = null,
        ?string $poNumber = null,
        ?RecurringInvoice $recurring = null,
    ): Invoice {
        $issuedOn ??= now();

        return Invoice::create([
            'number' => Invoice::nextNumber(Invoice::RecordPrefix),
            'team_id' => $team->id,
            'recurring_invoice_id' => $recurring?->id,
            'type' => InvoiceType::AdHoc,
            'note' => $note,
            'amount' => $amount,
            'paid_to_date' => $amount,
            'po_number' => $poNumber,
            'external_reference' => $externalReference,
            // A record is somebody else's document. Whatever the studio's own
            // VAT position is, it did not raise this and cannot charge on it.
            'vat_rate' => 0,
            'currency' => $team->currency,
            'issued_on' => $issuedOn,
            'due_on' => $issuedOn,
            'status' => InvoiceStatus::Paid,
            'record_only' => true,
            'paid_at' => $issuedOn,
        ]);
    }
}
