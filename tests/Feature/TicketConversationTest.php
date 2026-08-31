<?php

use App\Enums\BillingMode;
use App\Enums\ClientAccess;
use App\Enums\QuoteResponse;
use App\Enums\TicketStatus;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\User;
use Livewire\Livewire;

test('a client sees the conversation but never the internal notes', function () {
    $team = Team::factory()->create();
    $ticket = Ticket::factory()->for($team)->create(['reference' => 'RSC-2001']);

    TicketComment::factory()->for($ticket)->create(['body' => 'Shared with the client']);
    TicketComment::factory()->for($ticket)->internal()->create(['body' => 'Studio eyes only']);

    Livewire::actingAs(memberOf($team, ClientAccess::Full))
        ->test('pages::client.tickets')
        ->call('open', 'RSC-2001')
        ->assertSee('Shared with the client')
        ->assertDontSee('Studio eyes only');
});

test('a client can add a message to their ticket', function () {
    $team = Team::factory()->create();
    $ticket = Ticket::factory()->for($team)->create(['reference' => 'RSC-2002']);
    $user = memberOf($team, ClientAccess::Tickets);

    Livewire::actingAs($user)
        ->test('pages::client.tickets')
        ->call('open', 'RSC-2002')
        ->set('comment', 'Any progress on this?')
        ->call('addComment')
        ->assertHasNoErrors();

    $comment = $ticket->comments()->sole();

    expect($comment->body)->toBe('Any progress on this?')
        ->and($comment->user_id)->toBe($user->id)
        ->and($comment->is_internal)->toBeFalse();
});

test('a view-only person cannot comment', function () {
    $team = Team::factory()->create();
    Ticket::factory()->for($team)->create(['reference' => 'RSC-2003']);

    Livewire::actingAs(memberOf($team, ClientAccess::View))
        ->test('pages::client.tickets')
        ->call('open', 'RSC-2003')
        ->set('comment', 'Hello')
        ->call('addComment')
        ->assertForbidden();

    expect(TicketComment::count())->toBe(0);
});

test('a client cannot open another business\'s ticket', function () {
    $ticket = Ticket::factory()->for(Team::factory()->create())->create(['reference' => 'RSC-2004']);

    $component = Livewire::actingAs(memberOf(Team::factory()->create(), ClientAccess::Full))
        ->test('pages::client.tickets')
        ->call('open', 'RSC-2004');

    expect($component->instance()->selected)->toBeNull();

    $component->call('addComment')->assertStatus(404);

    expect($ticket->comments()->count())->toBe(0);
});

test('approving a quote moves the ticket into progress and records who decided', function () {
    $team = Team::factory()->create();
    $ticket = Ticket::factory()->for($team)->create([
        'reference' => 'RSC-2005',
        'status' => TicketStatus::QuoteSent,
        'billing_mode' => BillingMode::Chargeable,
        'quoted_hours' => 6,
        'quoted_rate' => 75,
        'quote_sent_at' => now()->subDay(),
    ]);

    Livewire::actingAs(memberOf($team, ClientAccess::Full))
        ->test('pages::client.tickets')
        ->call('open', 'RSC-2005')
        ->call('respondToQuote', 'approved');

    $ticket->refresh();

    expect($ticket->quote_response)->toBe(QuoteResponse::Approved)
        ->and($ticket->quote_responded_at)->not->toBeNull()
        ->and($ticket->status)->toBe(TicketStatus::InProgress)
        ->and($ticket->comments()->count())->toBe(1);
});

test('declining a quote reopens the ticket', function () {
    $team = Team::factory()->create();
    $ticket = Ticket::factory()->for($team)->create([
        'reference' => 'RSC-2006',
        'status' => TicketStatus::QuoteSent,
        'billing_mode' => BillingMode::Chargeable,
        'quoted_hours' => 2,
        'quoted_rate' => 65,
        'quote_sent_at' => now()->subDay(),
    ]);

    Livewire::actingAs(memberOf($team, ClientAccess::Full))
        ->test('pages::client.tickets')
        ->call('open', 'RSC-2006')
        ->call('respondToQuote', 'declined');

    $ticket->refresh();

    expect($ticket->quote_response)->toBe(QuoteResponse::Declined)
        ->and($ticket->status)->toBe(TicketStatus::Open);
});

test('only someone with billing access can answer a quote', function () {
    $team = Team::factory()->create();
    $ticket = Ticket::factory()->for($team)->create([
        'reference' => 'RSC-2007',
        'billing_mode' => BillingMode::Chargeable,
        'quoted_hours' => 2,
        'quoted_rate' => 65,
        'quote_sent_at' => now()->subDay(),
    ]);

    Livewire::actingAs(memberOf($team, ClientAccess::Tickets))
        ->test('pages::client.tickets')
        ->call('open', 'RSC-2007')
        ->call('respondToQuote', 'approved')
        ->assertForbidden();

    expect($ticket->fresh()->quote_response)->toBeNull();
});

test('a quote cannot be answered twice', function () {
    $team = Team::factory()->create();
    $ticket = Ticket::factory()->for($team)->create([
        'reference' => 'RSC-2008',
        'billing_mode' => BillingMode::Chargeable,
        'quoted_hours' => 2,
        'quoted_rate' => 65,
        'quote_sent_at' => now()->subDay(),
        'quote_response' => QuoteResponse::Approved,
        'quote_responded_at' => now()->subHour(),
    ]);

    Livewire::actingAs(memberOf($team, ClientAccess::Full))
        ->test('pages::client.tickets')
        ->call('open', 'RSC-2008')
        ->call('respondToQuote', 'declined')
        ->assertStatus(404);

    expect($ticket->fresh()->quote_response)->toBe(QuoteResponse::Approved);
});

test('the studio can reply to the client or keep a private note', function () {
    $ticket = Ticket::factory()->create(['reference' => 'RSC-2009']);
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.queue')
        ->call('select', 'RSC-2009')
        ->set('replyMode', 'client')
        ->set('reply', 'Looking at it now.')
        ->call('postReply')
        ->assertHasNoErrors()
        ->set('replyMode', 'internal')
        ->set('reply', 'Root cause is the hard coded rate.')
        ->call('postReply')
        ->assertHasNoErrors();

    $comments = $ticket->comments()->get();

    expect($comments)->toHaveCount(2)
        ->and($comments->firstWhere('body', 'Looking at it now.')->is_internal)->toBeFalse()
        ->and($comments->firstWhere('body', 'Root cause is the hard coded rate.')->is_internal)->toBeTrue();
});

test('selecting a ticket opens the detail panel for small screens', function () {
    Ticket::factory()->create(['reference' => 'RSC-2010']);

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.queue')
        ->assertSet('detailOpen', false)
        ->call('select', 'RSC-2010')
        ->assertSet('detailOpen', true)
        ->call('closeDetail')
        ->assertSet('detailOpen', false);
});
