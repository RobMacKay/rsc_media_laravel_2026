<?php

use App\Enums\ClientAccess;
use App\Enums\TicketPriority;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TicketRaised;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

test('the queue leads with the most urgent ticket by default', function () {
    $team = Team::factory()->create();
    Ticket::factory()->for($team)->create(['reference' => 'RSC-8001', 'priority' => TicketPriority::Low, 'created_at' => now()]);
    Ticket::factory()->for($team)->create(['reference' => 'RSC-8002', 'priority' => TicketPriority::Urgent, 'created_at' => now()->subWeek()]);

    $queue = Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.queue');

    expect($queue->instance()->tickets->pluck('reference')->all())->toBe(['RSC-8002', 'RSC-8001']);
});

test('the queue can be read newest first instead', function () {
    $team = Team::factory()->create();
    Ticket::factory()->for($team)->create(['reference' => 'RSC-8001', 'priority' => TicketPriority::Low, 'created_at' => now()]);
    Ticket::factory()->for($team)->create(['reference' => 'RSC-8002', 'priority' => TicketPriority::Urgent, 'created_at' => now()->subWeek()]);

    $queue = Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.queue')
        ->call('sortBy', 'newest');

    expect($queue->instance()->tickets->pluck('reference')->all())->toBe(['RSC-8001', 'RSC-8002']);
});

test('an unknown ordering is refused', function () {
    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.queue')
        ->call('sortBy', 'whatever')
        ->assertStatus(404);
});

test('a ticket the studio has never opened is flagged as new, and stops being once read', function () {
    $admin = User::factory()->admin()->create();
    $ticket = Ticket::factory()->for(Team::factory())->create(['reference' => 'RSC-8003']);

    Livewire::actingAs($admin)
        ->test('pages::admin.queue')
        ->assertSee('1 new ticket')
        ->assertSee('new')
        ->call('select', $ticket->reference)
        ->assertDontSee('1 new ticket');
});

test('the studio is emailed when a client raises a ticket', function () {
    Notification::fake();

    $admin = User::factory()->admin()->create();
    $team = Team::factory()->create();

    Livewire::actingAs(memberOf($team, ClientAccess::Tickets))
        ->test('pages::client.tickets')
        ->set('subject', 'Checkout is throwing a 500')
        ->set('type', 'bug')
        ->set('priority', 'urgent')
        ->set('description', 'Nobody can pay.')
        ->call('save')
        ->assertHasNoErrors();

    Notification::assertSentTo($admin, TicketRaised::class, function (TicketRaised $notification) use ($admin) {
        $mail = $notification->toMail($admin);

        return str_contains($mail->subject, 'URGENT')
            && str_contains($mail->subject, 'Checkout is throwing a 500');
    });
});

test('clients are not emailed about their own new ticket', function () {
    Notification::fake();

    $team = Team::factory()->create();
    $client = memberOf($team, ClientAccess::Tickets);

    Livewire::actingAs($client)
        ->test('pages::client.tickets')
        ->set('subject', 'Small copy change')
        ->set('type', 'bug')
        ->set('priority', 'low')
        ->set('description', 'Swap the strapline.')
        ->call('save')
        ->assertHasNoErrors();

    Notification::assertNotSentTo($client, TicketRaised::class);
});
