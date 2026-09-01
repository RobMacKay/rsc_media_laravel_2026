<?php

use App\Enums\BillingMode;
use App\Enums\InvoiceType;
use App\Enums\QuoteResponse;
use App\Enums\TicketStatus;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\StudioSetting;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\User;
use Livewire\Livewire;

/**
 * Build a chargeable ticket the client has signed off.
 */
function approvedChargeableTicket(Team $team, int $hours = 4, int $rate = 65): Ticket
{
    return Ticket::factory()->for($team)->create([
        'status' => TicketStatus::Resolved,
        'billing_mode' => BillingMode::Chargeable,
        'quoted_hours' => $hours,
        'quoted_rate' => $rate,
        'quote_sent_at' => now()->subWeek(),
        'quote_response' => QuoteResponse::Approved,
        'quote_responded_at' => now()->subDays(6),
    ]);
}

test('a project knows what is left to invoice', function () {
    $team = Team::factory()->create();
    $project = Project::factory()->for($team)->create(['agreed_value' => 6600]);

    expect($project->balanceToInvoice())->toBe(6600);

    Invoice::factory()->for($team)->create([
        'project_id' => $project->id,
        'amount' => 2640,
        'type' => InvoiceType::Deposit,
    ]);

    expect($project->fresh()->contractInvoiced())->toBe(2640)
        ->and($project->fresh()->balanceToInvoice())->toBe(3960);
});

test('ad hoc work does not eat into a fixed project price', function () {
    $team = Team::factory()->create();
    $project = Project::factory()->for($team)->create(['agreed_value' => 7680]);

    Invoice::factory()->for($team)->create([
        'project_id' => $project->id,
        'amount' => 4608,
        'type' => InvoiceType::Deposit,
    ]);

    // A chargeable ticket billed against the same project is extra to the
    // fixed price, so the balance still owes the rest of the contract.
    $ticket = approvedChargeableTicket($team, hours: 4, rate: 65);
    $ticket->update(['project_id' => $project->id]);

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.invoices')
        ->call('raiseForTicket', $ticket->id);

    expect($project->fresh()->balanceToInvoice())->toBe(3072);
});

test('plan invoices do not eat into a fixed project price', function () {
    $team = Team::factory()->create();
    $project = Project::factory()->for($team)->create(['agreed_value' => 1000]);

    Invoice::factory()->for($team)->create([
        'project_id' => $project->id,
        'amount' => 180,
        'type' => InvoiceType::Plan,
    ]);

    expect($project->fresh()->balanceToInvoice())->toBe(1000);
});

test('a project with no agreed price has nothing to bill against', function () {
    $team = Team::factory()->create();
    $carePlan = Project::factory()->for($team)->create(['agreed_value' => null]);

    Invoice::factory()->for($team)->create(['project_id' => $carePlan->id, 'amount' => 180]);

    expect($carePlan->balanceToInvoice())->toBe(0);
});

test('over-invoicing never produces a negative balance', function () {
    $team = Team::factory()->create();
    $project = Project::factory()->for($team)->create(['agreed_value' => 1000]);

    Invoice::factory()->for($team)->create([
        'project_id' => $project->id,
        'amount' => 1200,
        'type' => InvoiceType::Final,
    ]);

    expect($project->balanceToInvoice())->toBe(0);
});

test('the studio can raise the final invoice for a project', function () {
    StudioSetting::current()->update(['vat_registered' => true, 'vat_rate' => 20, 'payment_terms_days' => 21]);

    $team = Team::factory()->create(['payment_terms_days' => 14]);
    $project = Project::factory()->for($team)->create(['title' => 'Site rebuild', 'agreed_value' => 7680]);
    Invoice::factory()->for($team)->create([
        'project_id' => $project->id,
        'amount' => 4608,
        'type' => InvoiceType::Deposit,
    ]);

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.invoices')
        ->call('raiseFinal', $project->id)
        ->assertHasNoErrors();

    $final = Invoice::where('type', InvoiceType::Final)->sole();

    expect($final->amount)->toBe(3072)
        ->and($final->project_id)->toBe($project->id)
        ->and($final->vat_rate)->toBe(20.0)
        ->and($final->due_on->toDateString())->toBe(now()->addDays(14)->toDateString())
        ->and($project->fresh()->balanceToInvoice())->toBe(0);
});

test('a fully invoiced project cannot be billed again', function () {
    $team = Team::factory()->create();
    $project = Project::factory()->for($team)->create(['agreed_value' => 1000]);
    Invoice::factory()->for($team)->create([
        'project_id' => $project->id,
        'amount' => 1000,
        'type' => InvoiceType::Final,
    ]);

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.invoices')
        ->call('raiseFinal', $project->id)
        ->assertStatus(422);

    expect(Invoice::count())->toBe(1);
});

test('the studio can invoice an approved chargeable ticket', function () {
    $team = Team::factory()->create();
    $ticket = approvedChargeableTicket($team, hours: 4, rate: 65);

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.invoices')
        ->call('raiseForTicket', $ticket->id)
        ->assertHasNoErrors();

    $invoice = Invoice::sole();

    expect($invoice->amount)->toBe(260)
        ->and($invoice->type)->toBe(InvoiceType::AdHoc)
        ->and($invoice->ticket_id)->toBe($ticket->id)
        ->and($invoice->note)->toContain($ticket->reference);
});

test('a ticket cannot be invoiced twice', function () {
    $team = Team::factory()->create();
    $ticket = approvedChargeableTicket($team);

    $component = Livewire::actingAs(User::factory()->admin()->create())->test('pages::admin.invoices');

    $component->call('raiseForTicket', $ticket->id)->assertHasNoErrors();

    expect($ticket->fresh()->isReadyToInvoice())->toBeFalse();

    $component->call('raiseForTicket', $ticket->id)->assertStatus(422);

    expect(Invoice::count())->toBe(1);
});

test('support-hours and no-charge tickets are never billable', function () {
    $team = Team::factory()->create();

    foreach ([BillingMode::SupportHours, BillingMode::NoCharge] as $mode) {
        $ticket = approvedChargeableTicket($team);
        $ticket->update(['billing_mode' => $mode]);

        expect($ticket->fresh()->isReadyToInvoice())->toBeFalse();
    }
});

test('a chargeable ticket the client has not approved is not billable', function () {
    $team = Team::factory()->create();
    $ticket = approvedChargeableTicket($team);
    $ticket->update(['quote_response' => null, 'quote_responded_at' => null]);

    expect($ticket->fresh()->isReadyToInvoice())->toBeFalse();

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.invoices')
        ->call('raiseForTicket', $ticket->id)
        ->assertStatus(422);

    expect(Invoice::count())->toBe(0);
});

test('the billing queue lists what is owed and drops it once raised', function () {
    $team = Team::factory()->create(['name' => 'Fettes Dental']);
    $project = Project::factory()->for($team)->create(['title' => 'Site rebuild', 'agreed_value' => 7680]);
    $ticket = approvedChargeableTicket($team);

    $component = Livewire::actingAs(User::factory()->admin()->create())->test('pages::admin.invoices');

    expect($component->instance()->projectsToBill)->toHaveCount(1)
        ->and($component->instance()->ticketsToBill)->toHaveCount(1);

    $component->assertSee('Site rebuild')->assertSee('Raise final')->assertSee('Raise invoice');

    $component->call('raiseFinal', $project->id)->call('raiseForTicket', $ticket->id);

    expect($component->instance()->projectsToBill)->toHaveCount(0)
        ->and($component->instance()->ticketsToBill)->toHaveCount(0);
});

test('the billing queue is closed to clients', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('admin.invoices'))
        ->assertForbidden();
});
