<?php

use App\Enums\ClientAccess;
use App\Enums\TeamRole;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\User;

test('the dashboard shows the current project and open tickets', function () {
    $team = Team::factory()->create();
    $user = memberOf($team, ClientAccess::Full, TeamRole::Owner);

    Project::factory()->for($team)->create(['title' => 'Quote and job tracker', 'phase' => 'build', 'percent' => 64]);
    Ticket::factory()->for($team)->create(['title' => 'Quote PDF shows the wrong VAT rate']);

    $this->actingAs($user)
        ->get(route('client.dashboard'))
        ->assertOk()
        ->assertSee('Quote and job tracker')
        ->assertSee('64%')
        ->assertSee('Quote PDF shows the wrong VAT rate');
});

test('a client only ever sees their own business', function () {
    $team = Team::factory()->create();
    $user = memberOf($team, ClientAccess::Full, TeamRole::Owner);

    Ticket::factory()->for($team)->create(['title' => 'Our own ticket']);
    Ticket::factory()->for(Team::factory()->create())->create(['title' => 'Somebody elses ticket']);

    $this->actingAs($user)
        ->get(route('client.tickets'))
        ->assertOk()
        ->assertSee('Our own ticket')
        ->assertDontSee('Somebody elses ticket');
});

test('invoices are hidden from people without billing access', function () {
    $team = Team::factory()->create();

    Invoice::factory()->for($team)->create();

    $this->actingAs(memberOf($team, ClientAccess::Tickets))
        ->get(route('client.invoices'))
        ->assertForbidden();

    $this->actingAs(memberOf($team, ClientAccess::Full))
        ->get(route('client.invoices'))
        ->assertOk();
});

test('invoice totals include VAT', function () {
    $team = Team::factory()->create();
    $user = memberOf($team, ClientAccess::Full);

    Invoice::factory()->for($team)->create(['amount' => 180, 'vat_rate' => 20]);

    $this->actingAs($user)
        ->get(route('client.invoices'))
        ->assertOk()
        ->assertSee('£216');
});

test('guests are sent to the login screen', function () {
    $this->get(route('client.tickets'))->assertRedirect(route('login'));
});
