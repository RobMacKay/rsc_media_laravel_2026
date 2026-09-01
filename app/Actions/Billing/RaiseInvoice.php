<?php

namespace App\Actions\Billing;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\StudioSetting;
use App\Models\Team;
use App\Models\Ticket;

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
        int $amount,
        ?Project $project = null,
        ?Ticket $ticket = null,
        InvoiceStatus $status = InvoiceStatus::Sent,
    ): Invoice {
        return Invoice::create([
            'number' => Invoice::nextNumber(),
            'team_id' => $team->id,
            'project_id' => $project?->id,
            'ticket_id' => $ticket?->id,
            'type' => $type,
            'note' => $note,
            'amount' => $amount,
            'vat_rate' => $this->settings->effectiveVatRate(),
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
            amount: (int) round($ticket->quoteTotal()),
            project: $ticket->project,
            ticket: $ticket,
        );
    }
}
