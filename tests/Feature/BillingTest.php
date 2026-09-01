<?php

use App\Enums\BillingMode;
use App\Enums\InvoiceType;
use App\Enums\QuoteResponse;
use App\Enums\TicketStatus;
use App\Models\Invoice;
use App\Models\Plan;
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

/**
 * Put a client on a plan at the given monthly price.
 */
function clientOnPlan(int $price = 180, string $name = 'Care & Support'): Team
{
    return Team::factory()->create([
        'plan_id' => Plan::factory()->create(['name' => $name, 'price' => $price])->id,
    ]);
}

test('the scheduled run raises a plan invoice for every client on a plan', function () {
    $braemar = clientOnPlan(180);
    $glencoe = clientOnPlan(180);
    $fettes = clientOnPlan(75, 'Essential');

    $this->artisan('invoices:raise-plans')->assertSuccessful();

    expect(Invoice::where('type', InvoiceType::Plan)->count())->toBe(3)
        ->and((int) $braemar->invoices()->sum('amount'))->toBe(180)
        ->and((int) $fettes->invoices()->sum('amount'))->toBe(75);

    $note = $glencoe->invoices()->sole()->note;

    expect($note)->toContain('Care & Support')->toContain(now()->format('F'));
});

test('the scheduled run does not bill the same month twice', function () {
    clientOnPlan();

    $this->artisan('invoices:raise-plans')->assertSuccessful();
    $this->artisan('invoices:raise-plans')->assertSuccessful();

    expect(Invoice::count())->toBe(1);
});

test('raising by hand and the scheduled run do not double up', function () {
    $team = clientOnPlan();

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.invoices')
        ->call('raisePlan', $team->id)
        ->assertHasNoErrors();

    expect(Invoice::count())->toBe(1);

    // The schedule still fires on the 1st, and must find nothing left to do.
    $this->artisan('invoices:raise-plans')->assertSuccessful();

    expect(Invoice::count())->toBe(1);
});

test('the studio can send every plan invoice early from the screen', function () {
    clientOnPlan();
    clientOnPlan(75, 'Essential');

    $component = Livewire::actingAs(User::factory()->admin()->create())->test('pages::admin.invoices');

    expect($component->instance()->plansToBill)->toHaveCount(2);

    $component->call('raiseAllPlans')->assertHasNoErrors();

    expect(Invoice::where('type', InvoiceType::Plan)->count())->toBe(2)
        ->and($component->instance()->plansToBill)->toHaveCount(0);

    $component->call('raiseAllPlans')->assertStatus(422);
});

test('a client with no plan is never billed a retainer', function () {
    Team::factory()->create(['plan_id' => null]);

    $this->artisan('invoices:raise-plans')->assertSuccessful();

    expect(Invoice::count())->toBe(0);
});

test('a free plan raises nothing', function () {
    clientOnPlan(0, 'Free');

    $this->artisan('invoices:raise-plans')->assertSuccessful();

    expect(Invoice::count())->toBe(0);
});

test('next month is due again once the month rolls over', function () {
    $team = clientOnPlan();

    $this->artisan('invoices:raise-plans')->assertSuccessful();
    expect(Invoice::count())->toBe(1);

    $this->travel(1)->months();

    $this->artisan('invoices:raise-plans')->assertSuccessful();

    expect(Invoice::count())->toBe(2)
        ->and((int) $team->invoices()->sum('amount'))->toBe(360);
});

test('pretend lists what is due without raising it', function () {
    clientOnPlan();

    $this->artisan('invoices:raise-plans --pretend')->assertSuccessful();

    expect(Invoice::count())->toBe(0);
});

test('the plan invoice follows the client\'s own payment terms', function () {
    $team = clientOnPlan();
    $team->update(['payment_terms_days' => 14]);

    $this->artisan('invoices:raise-plans')->assertSuccessful();

    expect(Invoice::sole()->due_on->toDateString())->toBe(now()->addDays(14)->toDateString());
});
