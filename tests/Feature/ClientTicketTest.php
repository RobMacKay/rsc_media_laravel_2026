<?php

use App\Enums\ClientAccess;
use App\Enums\TicketStatus;
use App\Models\Project;
use App\Models\Team;
use App\Models\Ticket;
use Livewire\Livewire;

test('a client can raise a ticket', function () {
    $team = Team::factory()->create();
    $user = memberOf($team, ClientAccess::Tickets);

    Livewire::actingAs($user)
        ->test('pages::client.tickets')
        ->set('subject', 'Contact form emails going to spam')
        ->set('type', 'bug')
        ->set('priority', 'high')
        ->set('system', 'braemarjoinery.co.uk')
        ->set('description', 'Enquiries are landing in junk on Outlook.')
        ->call('save')
        ->assertHasNoErrors();

    $ticket = Ticket::sole();

    expect($ticket->title)->toBe('Contact form emails going to spam')
        ->and($ticket->team_id)->toBe($team->id)
        ->and($ticket->reported_by)->toBe($user->id)
        ->and($ticket->status)->toBe(TicketStatus::Open)
        ->and($ticket->reference)->toStartWith('RSC-');
});

test('a ticket needs a title and a description', function () {
    $user = memberOf(Team::factory()->create(), ClientAccess::Tickets);

    Livewire::actingAs($user)
        ->test('pages::client.tickets')
        ->set('subject', '')
        ->set('description', '')
        ->call('save')
        ->assertHasErrors(['subject', 'description']);

    expect(Ticket::count())->toBe(0);
});

test('view-only people cannot raise tickets', function () {
    $user = memberOf(Team::factory()->create(), ClientAccess::View);

    Livewire::actingAs($user)
        ->test('pages::client.tickets')
        ->set('subject', 'Something')
        ->set('description', 'Something else')
        ->call('save')
        ->assertForbidden();

    expect(Ticket::count())->toBe(0);
});

test('ticket references do not collide', function () {
    $team = Team::factory()->create();
    $user = memberOf($team, ClientAccess::Tickets);

    Ticket::factory()->for($team)->create(['reference' => 'RSC-1048']);

    Livewire::actingAs($user)
        ->test('pages::client.tickets')
        ->set('subject', 'Another one')
        ->set('description', 'Details.')
        ->call('save')
        ->assertHasNoErrors();

    expect(Ticket::where('reference', 'RSC-1049')->exists())->toBeTrue();
});

test('the status filter narrows the list', function () {
    $team = Team::factory()->create();
    $user = memberOf($team, ClientAccess::Full);

    Ticket::factory()->for($team)->create(['title' => 'Still open']);
    Ticket::factory()->for($team)->resolved()->create(['title' => 'All done']);

    Livewire::actingAs($user)
        ->test('pages::client.tickets')
        ->set('filter', 'resolved')
        ->assertSee('All done')
        ->assertDontSee('Still open');
});

test('the new ticket form lists the systems a ticket can be raised against', function () {
    $team = Team::factory()->create();
    $user = memberOf($team, ClientAccess::Tickets);

    Project::factory()->for($team)->create(['title' => 'Quote and job tracker']);
    Ticket::factory()->for($team)->create(['system' => 'braemarjoinery.co.uk']);
    Ticket::factory()->for($team)->create(['system' => 'braemarjoinery.co.uk']);
    Ticket::factory()->for($team)->create(['system' => null]);

    $component = Livewire::actingAs($user)
        ->test('pages::client.tickets')
        ->set('formOpen', true);

    // Deduplicated, with the nulls dropped, and readable in the dropdown.
    expect($component->instance()->systems)->toBe([
        'braemarjoinery.co.uk',
        'Quote and job tracker',
    ]);

    $component->assertSee('braemarjoinery.co.uk')->assertSee('Quote and job tracker');
});
