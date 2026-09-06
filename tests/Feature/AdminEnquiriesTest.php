<?php

use App\Enums\ClientAccess;
use App\Models\Enquiry;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

test('the studio can see what has come in through the contact form', function () {
    Enquiry::factory()->create([
        'name' => 'Kirsty Munro',
        'company' => 'Braemar Joinery',
        'email' => 'kirsty@braemarjoinery.co.uk',
        'message' => 'Our booking form drops the phone number.',
        'topic' => 'existing',
    ]);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.enquiries'))
        ->assertOk()
        ->assertSee('Kirsty Munro')
        ->assertSee('Braemar Joinery')
        ->assertSee('Our booking form drops the phone number.')
        ->assertSee('kirsty@braemarjoinery.co.uk')
        ->assertSee('1 waiting on you');
});

test('a client cannot see other people\'s enquiries', function () {
    Enquiry::factory()->create(['name' => 'Kirsty Munro']);

    $this->actingAs(memberOf(Team::factory()->create(), ClientAccess::Full))
        ->get(route('admin.enquiries'))
        ->assertForbidden();
});

test('the list opens on what is still waiting', function () {
    Enquiry::factory()->create(['name' => 'Still Waiting']);
    Enquiry::factory()->create(['name' => 'Already Sorted', 'handled_at' => now()]);

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.enquiries')
        ->assertSee('Still Waiting')
        ->assertDontSee('Already Sorted')
        ->set('filter', 'handled')
        ->assertSee('Already Sorted')
        ->assertDontSee('Still Waiting')
        ->set('filter', 'all')
        ->assertSee('Still Waiting')
        ->assertSee('Already Sorted');
});

test('an enquiry can be marked as dealt with, and put back', function () {
    $enquiry = Enquiry::factory()->create(['name' => 'Kirsty Munro']);

    $component = Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.enquiries')
        ->call('setHandled', $enquiry->id, true);

    expect($enquiry->fresh()->isHandled())->toBeTrue();

    $component->call('setHandled', $enquiry->id, false);

    expect($enquiry->fresh()->isHandled())->toBeFalse();
});

test('the count only reflects what is still waiting', function () {
    Enquiry::factory()->count(2)->create();
    Enquiry::factory()->create(['handled_at' => now()]);

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.enquiries')
        ->assertSee('2 waiting on you')
        ->assertSee('3 in total');
});

test('spam can be deleted outright', function () {
    $enquiry = Enquiry::factory()->create(['name' => 'Cheap Backlinks']);

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.enquiries')
        ->call('delete', $enquiry->id)
        ->assertDontSee('Cheap Backlinks');

    expect(Enquiry::count())->toBe(0);
});

test('an empty list says which list is empty', function () {
    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.enquiries')
        ->assertSee('Nothing waiting')
        ->set('filter', 'all')
        ->assertSee('Nobody has used the contact form yet.');
});

test('the reply link carries the address and a subject', function () {
    $enquiry = Enquiry::factory()->create(['email' => 'kirsty@braemarjoinery.co.uk']);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.enquiries'))
        ->assertSee('mailto:kirsty@braemarjoinery.co.uk?subject=', false);
});

test('the received time is shown in the studio timezone', function () {
    Enquiry::factory()->create([
        'created_at' => CarbonImmutable::parse('2026-07-03 23:30:00', 'UTC'),
    ]);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.enquiries'))
        ->assertSee('4 Jul 2026, 00:30');
});

test('the admin nav offers the enquiries screen', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.queue'))
        ->assertOk()
        ->assertSee(route('admin.enquiries'));
});
