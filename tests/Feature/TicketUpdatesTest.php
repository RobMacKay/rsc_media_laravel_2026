<?php

use App\Actions\Billing\RaiseInvoice;
use App\Enums\ClientAccess;
use App\Enums\InvoiceType;
use App\Enums\ProposalStatus;
use App\Enums\TicketStatus;
use App\Models\Proposal;
use App\Models\StudioSetting;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\User;
use Livewire\Livewire;

/**
 * Send a quote on a ticket the way the studio actually does.
 */
function quoteFor(Ticket $ticket, User $admin): void
{
    Livewire::actingAs($admin)
        ->test('pages::admin.queue')
        ->call('select', $ticket->reference)
        ->call('sendQuote', [
            'hours' => 4,
            'rate' => 65,
            'billing_mode' => 'chargeable',
            'priority' => 'normal',
            'target_on' => null,
        ])
        ->assertHasNoErrors();
}

test('a client is shown a quote waiting on them, and can approve it', function () {
    $team = Team::factory()->create();
    $client = memberOf($team, ClientAccess::Full);
    $ticket = Ticket::factory()->for($team)->create(['reference' => 'RSC-9001', 'status' => TicketStatus::Open]);

    quoteFor($ticket, User::factory()->admin()->create());

    Livewire::actingAs($client)
        ->test('pages::client.tickets')
        // The list itself flags it, without having to open the ticket first.
        ->assertSee('needs you')
        ->assertSee('1 quote to approve')
        ->call('openTicket', 'RSC-9001')
        ->assertSee('Approve the quote')
        ->call('respondToQuote', 'approved')
        ->assertHasNoErrors();

    $ticket->refresh();

    expect($ticket->quote_response?->value)->toBe('approved')
        ->and($ticket->status)->toBe(TicketStatus::InProgress);
});

test('the quote flag clears once the client has answered', function () {
    $team = Team::factory()->create();
    $client = memberOf($team, ClientAccess::Full);
    $ticket = Ticket::factory()->for($team)->create(['reference' => 'RSC-9002', 'status' => TicketStatus::Open]);

    quoteFor($ticket, User::factory()->admin()->create());

    Livewire::actingAs($client)
        ->test('pages::client.tickets')
        ->call('openTicket', 'RSC-9002')
        ->call('respondToQuote', 'declined');

    Livewire::actingAs($client)
        ->test('pages::client.tickets')
        ->assertDontSee('needs you')
        ->assertDontSee('quote to approve');
});

test('a ticket nobody has opened counts as an update', function () {
    $team = Team::factory()->create();
    $client = memberOf($team, ClientAccess::Full);
    $ticket = Ticket::factory()->for($team)->create(['reference' => 'RSC-9003']);

    expect($ticket->load('reads')->hasUpdateFor($client))->toBeTrue();
});

test('opening a ticket clears its update flag', function () {
    $team = Team::factory()->create();
    $client = memberOf($team, ClientAccess::Full);
    $ticket = Ticket::factory()->for($team)->create(['reference' => 'RSC-9004']);

    Livewire::actingAs($client)
        ->test('pages::client.tickets')
        ->assertSee('1 ticket has moved')
        ->call('openTicket', 'RSC-9004')
        ->assertDontSee('ticket has moved');

    expect($ticket->fresh()->load('reads')->hasUpdateFor($client))->toBeFalse();
});

test('a reply from the studio marks the ticket updated for the client again', function () {
    $team = Team::factory()->create();
    $client = memberOf($team, ClientAccess::Full);
    $admin = User::factory()->admin()->create();
    $ticket = Ticket::factory()->for($team)->create(['reference' => 'RSC-9005']);

    Livewire::actingAs($client)->test('pages::client.tickets')->call('openTicket', 'RSC-9005');

    expect($ticket->fresh()->load('reads')->hasUpdateFor($client))->toBeFalse();

    $this->travel(1)->minutes();

    Livewire::actingAs($admin)
        ->test('pages::admin.queue')
        ->call('select', 'RSC-9005')
        ->set('reply', 'Looking at this now.')
        ->call('postReply')
        ->assertHasNoErrors();

    expect($ticket->fresh()->load('reads')->hasUpdateFor($client))->toBeTrue();
});

test('one person reading a ticket does not clear it for their colleague', function () {
    $team = Team::factory()->create();
    $kirsty = memberOf($team, ClientAccess::Full);
    $alan = memberOf($team, ClientAccess::Full);
    $ticket = Ticket::factory()->for($team)->create(['reference' => 'RSC-9006']);

    Livewire::actingAs($kirsty)->test('pages::client.tickets')->call('openTicket', 'RSC-9006');

    $ticket = $ticket->fresh();

    expect($ticket->load('reads')->hasUpdateFor($kirsty))->toBeFalse()
        ->and($ticket->load('reads')->hasUpdateFor($alan))->toBeTrue();
});

test('the studio sees new tickets flagged, and clears them by opening', function () {
    $admin = User::factory()->admin()->create();
    Ticket::factory()->create(['reference' => 'RSC-9007']);

    Livewire::actingAs($admin)
        ->test('pages::admin.queue')
        ->assertSee('1 ticket has moved')
        ->call('select', 'RSC-9007')
        ->assertDontSee('ticket has moved');
});

test('the studio is told how many quotes are sitting with clients', function () {
    $admin = User::factory()->admin()->create();
    $ticket = Ticket::factory()->create(['reference' => 'RSC-9008', 'status' => TicketStatus::Open]);

    quoteFor($ticket, $admin);

    Livewire::actingAs($admin)
        ->test('pages::admin.queue')
        ->assertSee('1 quote with a client');
});

test('both ticket screens poll, so a change shows without a reload', function () {
    expect(file_get_contents(resource_path('views/pages/client/tickets.blade.php')))->toContain('wire:poll')
        ->and(file_get_contents(resource_path('views/pages/admin/queue.blade.php')))->toContain('wire:poll');
});

test('VAT is only mentioned when the studio actually charges it', function () {
    StudioSetting::current()->update(['vat_registered' => false]);

    $team = Team::factory()->create();
    $client = memberOf($team, ClientAccess::Full);
    $ticket = Ticket::factory()->for($team)->create(['reference' => 'RSC-9009', 'status' => TicketStatus::Open]);

    quoteFor($ticket, User::factory()->admin()->create());

    Livewire::actingAs($client)
        ->test('pages::client.tickets')
        ->call('openTicket', 'RSC-9009')
        ->assertSee('Nothing starts until you approve it.')
        ->assertDontSee('Excluding VAT');
});

test('VAT comes back once the studio is registered and has a number', function () {
    StudioSetting::current()->update([
        'vat_registered' => true,
        'vat_rate' => 20,
        'vat_number' => 'GB412887309',
    ]);

    $team = Team::factory()->create();
    $client = memberOf($team, ClientAccess::Full);
    $ticket = Ticket::factory()->for($team)->create(['reference' => 'RSC-9010', 'status' => TicketStatus::Open]);

    quoteFor($ticket, User::factory()->admin()->create());

    Livewire::actingAs($client)
        ->test('pages::client.tickets')
        ->call('openTicket', 'RSC-9010')
        ->assertSee('Excluding VAT');
});

test('an invoice with no VAT does not show an empty VAT line', function () {
    StudioSetting::current()->update(['vat_registered' => false]);

    $team = Team::factory()->create();
    $client = memberOf($team, ClientAccess::Full);

    $invoice = app(RaiseInvoice::class)->handle(
        team: $team,
        type: InvoiceType::AdHoc,
        note: 'Some work',
        amount: 900,
    );

    $this->actingAs($client)
        ->get(route('client.invoices.show', $invoice->number))
        ->assertOk()
        ->assertDontSee('VAT at')
        ->assertSee('No VAT is charged on this invoice.');
});

test('an invoice built through the container uses the studio settings, not class defaults', function () {
    // Every call site passes settings explicitly, but the container would
    // otherwise hand out a blank StudioSetting, quietly billing VAT and terms
    // from the model defaults instead of what the studio actually set.
    StudioSetting::current()->update(['vat_registered' => false, 'payment_terms_days' => 45]);

    $invoice = app(RaiseInvoice::class)->handle(
        team: Team::factory()->create(),
        type: InvoiceType::AdHoc,
        note: 'Some work',
        amount: 900,
    );

    expect((float) $invoice->vat_rate)->toBe(0.0)
        ->and($invoice->issued_on->diffInDays($invoice->due_on))->toBe(45.0);
});

test('your own reply does not come back flagged as new to you', function () {
    $team = Team::factory()->create();
    $client = memberOf($team, ClientAccess::Full);
    $ticket = Ticket::factory()->for($team)->create(['reference' => 'RSC-9011']);

    Livewire::actingAs($client)
        ->test('pages::client.tickets')
        ->call('openTicket', 'RSC-9011')
        ->set('comment', 'Any news on this?')
        ->call('addComment')
        ->assertHasNoErrors()
        ->assertDontSee('ticket has moved');

    expect($ticket->fresh()->load('reads')->hasUpdateFor($client))->toBeFalse();
});

test('approving a quote does not flag the ticket back to the client', function () {
    $team = Team::factory()->create();
    $client = memberOf($team, ClientAccess::Full);
    $ticket = Ticket::factory()->for($team)->create(['reference' => 'RSC-9012', 'status' => TicketStatus::Open]);

    quoteFor($ticket, User::factory()->admin()->create());

    Livewire::actingAs($client)
        ->test('pages::client.tickets')
        ->call('openTicket', 'RSC-9012')
        ->call('respondToQuote', 'approved')
        ->assertDontSee('ticket has moved');

    expect($ticket->fresh()->load('reads')->hasUpdateFor($client))->toBeFalse();
});

test('the studio replying does not flag the ticket back to the studio', function () {
    $admin = User::factory()->admin()->create();
    $ticket = Ticket::factory()->create(['reference' => 'RSC-9013']);

    Livewire::actingAs($admin)
        ->test('pages::admin.queue')
        ->call('select', 'RSC-9013')
        ->set('reply', 'On it today.')
        ->call('postReply')
        ->assertHasNoErrors()
        ->assertDontSee('ticket has moved');

    expect($ticket->fresh()->load('reads')->hasUpdateFor($admin))->toBeFalse();
});

test('but the other side still sees it as new', function () {
    $team = Team::factory()->create();
    $client = memberOf($team, ClientAccess::Full);
    $admin = User::factory()->admin()->create();
    $ticket = Ticket::factory()->for($team)->create(['reference' => 'RSC-9014']);

    Livewire::actingAs($client)->test('pages::client.tickets')->call('openTicket', 'RSC-9014');

    $this->travel(1)->minutes();

    Livewire::actingAs($admin)
        ->test('pages::admin.queue')
        ->call('select', 'RSC-9014')
        ->set('reply', 'Fixed and deployed.')
        ->call('postReply');

    $ticket = $ticket->fresh();

    expect($ticket->load('reads')->hasUpdateFor($admin))->toBeFalse()
        ->and($ticket->load('reads')->hasUpdateFor($client))->toBeTrue();
});

test('the VAT number is part of the switch, not decoration', function () {
    $settings = StudioSetting::current();

    // Registered, but no number to put on the invoice.
    $settings->update(['vat_registered' => true, 'vat_rate' => 20, 'vat_number' => null]);

    expect(StudioSetting::current()->effectiveVatRate())->toBe(0.0)
        ->and(StudioSetting::current()->chargesVat())->toBeFalse();

    $settings->update(['vat_number' => 'GB412887309']);

    expect(StudioSetting::current()->effectiveVatRate())->toBe(20.0)
        ->and(StudioSetting::current()->chargesVat())->toBeTrue();

    // And the toggle still wins on its own.
    $settings->update(['vat_registered' => false]);

    expect(StudioSetting::current()->effectiveVatRate())->toBe(0.0);
});

test('a proposed job quotes no VAT until there is a number to charge it under', function () {
    $team = Team::factory()->create();
    $client = memberOf($team, ClientAccess::Full);

    Proposal::factory()->for($team)->create([
        'status' => ProposalStatus::Sent,
        'price' => 3400,
        'deposit_percent' => 40,
    ]);

    StudioSetting::current()->update(['vat_registered' => true, 'vat_rate' => 20, 'vat_number' => null]);

    $this->actingAs($client)
        ->get(route('client.projects'))
        ->assertOk()
        ->assertSee('£3,400')
        ->assertDontSee('VAT');

    StudioSetting::current()->update(['vat_number' => 'GB412887309']);

    $this->actingAs($client)
        ->get(route('client.projects'))
        ->assertOk()
        ->assertSee('+ VAT');
});

test('an invoice raised with no VAT number carries no VAT', function () {
    StudioSetting::current()->update(['vat_registered' => true, 'vat_rate' => 20, 'vat_number' => null]);

    $invoice = app(RaiseInvoice::class)->handle(
        team: Team::factory()->create(),
        type: InvoiceType::AdHoc,
        note: 'Some work',
        amount: 900,
    );

    // The copy and the charge have to agree: hiding the label while still
    // adding 20% would be worse than either.
    expect((float) $invoice->vat_rate)->toBe(0.0)
        ->and($invoice->vatAmount())->toBe(0.0)
        ->and($invoice->total())->toBe(900.0);
});
