<?php

use App\Enums\BillingMode;
use App\Enums\InvoiceStatus;
use App\Enums\TicketStatus;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Project;
use App\Models\StudioSetting;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\User;
use Livewire\Livewire;

test('the admin portal is closed to clients', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('admin.queue'))
        ->assertForbidden();

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.queue'))
        ->assertOk();
});

test('the queue shows tickets from every client', function () {
    Ticket::factory()->for(Team::factory()->create(['name' => 'Braemar Joinery']))->create(['title' => 'VAT rate is wrong']);
    Ticket::factory()->for(Team::factory()->create(['name' => 'Glen Coe Cabins']))->create(['title' => 'Deposit field needed']);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.queue'))
        ->assertOk()
        ->assertSee('Braemar Joinery')
        ->assertSee('Glen Coe Cabins');
});

test('a chargeable quote moves the ticket to quote sent', function () {
    $ticket = Ticket::factory()->create(['reference' => 'RSC-1048', 'status' => TicketStatus::Open]);

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.queue')
        ->set('selectedReference', 'RSC-1048')
        ->call('sendQuote', [
            'hours' => 6,
            'rate' => 75,
            'billing_mode' => BillingMode::Chargeable->value,
            'priority' => 'normal',
            'target_on' => null,
        ])
        ->assertHasNoErrors();

    $ticket->refresh();

    expect($ticket->status)->toBe(TicketStatus::QuoteSent)
        ->and($ticket->quoteTotal())->toBe(450.0)
        ->and($ticket->quote_sent_at)->not->toBeNull();
});

test('logging time against support hours leaves the status alone', function () {
    $ticket = Ticket::factory()->create(['reference' => 'RSC-1050', 'status' => TicketStatus::InProgress]);

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.queue')
        ->set('selectedReference', 'RSC-1050')
        ->call('sendQuote', [
            'hours' => 1.5,
            'rate' => 65,
            'billing_mode' => BillingMode::SupportHours->value,
            'priority' => 'high',
            'target_on' => null,
        ])
        ->assertHasNoErrors();

    expect($ticket->fresh()->status)->toBe(TicketStatus::InProgress);
});

test('resolving a ticket stamps the closing date', function () {
    $ticket = Ticket::factory()->create(['reference' => 'RSC-1051']);

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.queue')
        ->set('selectedReference', 'RSC-1051')
        ->call('setStatus', TicketStatus::Resolved->value);

    expect($ticket->fresh()->resolved_at)->not->toBeNull();
});

test('job progress can be updated from the jobs board', function () {
    $project = Project::factory()->create(['percent' => 15]);

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.jobs')
        ->call('saveJob', $project->id, [
            'percent' => 65,
            'milestone' => 'Test site for review',
            'due_on' => '2026-08-21',
            'waiting_on_client' => '',
        ])
        ->assertHasNoErrors();

    $project->refresh();

    expect($project->percent)->toBe(65)
        ->and($project->milestone)->toBe('Test site for review')
        ->and($project->waiting_on_client)->toBeNull();
});

test('an invoice takes its VAT and terms from settings and the client', function () {
    StudioSetting::current()->update([
        'vat_registered' => true,
        'vat_rate' => 20,
        // VAT is only charged once there is a number to put on the invoice.
        'vat_number' => 'GB412887309',
        'payment_terms_days' => 21,
    ]);

    $team = Team::factory()->create(['payment_terms_days' => 14]);
    $project = Project::factory()->for($team)->create();

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.invoices')
        ->set('teamId', $team->id)
        ->set('projectId', $project->id)
        ->set('type', 'deposit')
        ->set('amount', 1000)
        ->set('note', 'Deposit — 40% of agreed fee')
        ->call('save')
        ->assertHasNoErrors();

    $invoice = Invoice::sole();

    expect($invoice->amount)->toBe(1000)
        ->and($invoice->vat_rate)->toBe(20.0)
        ->and($invoice->total())->toBe(1200.0)
        ->and($invoice->due_on->toDateString())->toBe(now()->addDays(14)->toDateString())
        ->and($invoice->number)->toStartWith('RSC-');
});

test('an unregistered studio raises invoices with no VAT', function () {
    StudioSetting::current()->update(['vat_registered' => false, 'vat_rate' => 20]);

    $team = Team::factory()->create();

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.invoices')
        ->set('teamId', $team->id)
        ->set('amount', 500)
        ->set('note', 'Extra training session')
        ->call('save')
        ->assertHasNoErrors();

    expect(Invoice::sole()->total())->toBe(500.0);
});

test('an invoice can be marked as paid', function () {
    $invoice = Invoice::factory()->create(['status' => InvoiceStatus::Overdue]);

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.invoices')
        ->call('markPaid', $invoice->id);

    $invoice->refresh();

    expect($invoice->status)->toBe(InvoiceStatus::Paid)
        ->and($invoice->paid_at)->not->toBeNull();
});

test('settings save the studio defaults, the plans and the client overrides', function () {
    $plan = Plan::factory()->create(['name' => 'Care & Support', 'price' => 180]);
    $team = Team::factory()->create(['name' => 'Braemar Joinery', 'plan_id' => $plan->id]);

    $component = Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.settings')
        ->set('studio.hour_rate', 80)
        ->set('studio.sort_code', '60-83-71')
        ->set('studio.account_number', '41028853')
        ->set('plans.0.price', 200)
        ->set('plans.0.features', "Hosting\nUpdates\n")
        ->set('clientId', $team->id)
        ->set('client.hour_rate', 95)
        ->call('save');

    $component->assertHasNoErrors();

    expect(StudioSetting::current()->hour_rate)->toBe(80)
        ->and($plan->fresh()->price)->toBe(200)
        ->and($plan->fresh()->features)->toBe(['Hosting', 'Updates'])
        ->and($team->fresh()->hour_rate)->toBe(95);
});

test('a malformed sort code is rejected', function () {
    Plan::factory()->create();

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.settings')
        ->set('studio.sort_code', '608371')
        ->call('save')
        ->assertHasErrors(['studio.sort_code']);
});

test('the studio can edit the company details that head an invoice', function () {
    Plan::factory()->create();

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.settings')
        ->set('studio.company_name', 'RSC Media Ltd')
        ->set('studio.company_number', 'SC512347')
        ->set('studio.address', "Unit 4, Bridgend Works\nDunkeld")
        ->set('studio.email', 'info@rscmedia.co.uk')
        ->set('studio.website', 'rscmedia.co.uk')
        ->call('save')
        ->assertHasNoErrors();

    $settings = StudioSetting::current();

    expect($settings->company_number)->toBe('SC512347')
        ->and($settings->addressLines())->toBe(['Unit 4, Bridgend Works', 'Dunkeld']);
});

test('a company name is required and the contact email must be one', function () {
    Plan::factory()->create();

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.settings')
        ->set('studio.company_name', '')
        ->set('studio.email', 'not-an-email')
        ->call('save')
        ->assertHasErrors(['studio.company_name', 'studio.email']);
});
