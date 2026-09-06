<?php

use App\Enums\ClientAccess;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\User;
use App\Support\DisplayTime;
use Carbon\CarbonImmutable;

test('a timestamp is shown in the studio timezone, not the one it is stored in', function () {
    // Half past midnight on 4 July in Britain is still 3 July in UTC.
    $stored = CarbonImmutable::parse('2026-07-03 23:30:00', 'UTC');

    expect($stored->format('j M, H:i'))->toBe('3 Jul, 23:30')
        ->and(shown($stored)->format('j M, H:i'))->toBe('4 Jul, 00:30');
});

test('winter needs no shifting, because Britain is on UTC then', function () {
    $stored = CarbonImmutable::parse('2026-01-15 09:00:00', 'UTC');

    expect(shown($stored)->format('j M, H:i'))->toBe('15 Jan, 09:00');
});

test('nothing shown is still nothing, so a nullable column reads the same', function () {
    expect(shown(null))->toBeNull()
        ->and(DisplayTime::of(null))->toBeNull();
});

test('the original is left alone, so nothing downstream sees a shifted time', function () {
    $stored = CarbonImmutable::parse('2026-07-03 23:30:00', 'UTC');

    shown($stored);

    expect($stored->format('j M, H:i'))->toBe('3 Jul, 23:30');
});

test('the admin queue shows a ticket raised after midnight on the right day', function () {
    $team = Team::factory()->create();
    $ticket = Ticket::factory()->for($team)->create([
        'reference' => 'RSC-7001',
        'created_at' => CarbonImmutable::parse('2026-07-03 23:30:00', 'UTC'),
    ]);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.queue', ['ticket' => $ticket->reference]))
        ->assertOk()
        ->assertSee('4 Jul, 00:30')
        ->assertDontSee('3 Jul, 23:30');
});

test('the client sees the same day the studio does', function () {
    $team = Team::factory()->create();
    Ticket::factory()->for($team)->create([
        'reference' => 'RSC-7002',
        'created_at' => CarbonImmutable::parse('2026-07-03 23:30:00', 'UTC'),
    ]);

    $this->actingAs(memberOf($team, ClientAccess::Full))
        ->get(route('client.tickets', ['ticket' => 'RSC-7002']))
        ->assertOk()
        ->assertSee('4 Jul 2026');
});

test('the timezone is configurable, and falls back to UTC rather than guessing', function () {
    config(['app.display_timezone' => 'Australia/Perth']);
    expect(DisplayTime::timezone())->toBe('Australia/Perth');

    config(['app.display_timezone' => null]);
    expect(DisplayTime::timezone())->toBe('UTC');
});
