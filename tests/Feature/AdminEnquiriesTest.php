<?php

use App\Enums\ClientAccess;
use App\Models\Enquiry;
use App\Models\Team;
use App\Models\User;
use App\Notifications\SetYourPassword;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
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

test('an enquiry can be opened as a client account, prefilled from what they sent', function () {
    Notification::fake();

    $enquiry = Enquiry::factory()->create([
        'name' => 'Alan Petrie',
        'company' => 'Petrie Plant Hire',
        'email' => 'alan@petrieplant.co.uk',
    ]);

    $component = Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.enquiries')
        ->call('startAccount', $enquiry->id)
        ->assertSet('newBusiness', 'Petrie Plant Hire')
        ->assertSet('newContactName', 'Alan Petrie')
        ->assertSet('newContactEmail', 'alan@petrieplant.co.uk');

    $component->set('newJobTitle', 'Director')
        ->call('openAccount')
        ->assertHasNoErrors();

    $team = Team::whereName('Petrie Plant Hire')->sole();
    $user = User::whereEmail('alan@petrieplant.co.uk')->sole();

    expect($user->must_set_password)->toBeTrue()
        ->and($team->members->pluck('id'))->toContain($user->id)
        ->and($user->accessFor($team)->canSeeBilling())->toBeTrue();

    // Opening the account is dealing with the enquiry, and it remembers which.
    expect($enquiry->fresh()->team_id)->toBe($team->id)
        ->and($enquiry->fresh()->isHandled())->toBeTrue();

    Notification::assertSentTo($user, SetYourPassword::class);
});

test('a sole trader with no company name falls back to their own', function () {
    Notification::fake();

    $enquiry = Enquiry::factory()->create(['name' => 'Morag Bell', 'company' => null]);

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.enquiries')
        ->call('startAccount', $enquiry->id)
        ->assertSet('newBusiness', 'Morag Bell');
});

test('someone who already has an account is told so, rather than getting a second one', function () {
    Notification::fake();

    $existing = User::factory()->create(['email' => 'kirsty@braemarjoinery.co.uk']);
    $enquiry = Enquiry::factory()->create(['email' => 'kirsty@braemarjoinery.co.uk']);

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.enquiries')
        ->call('startAccount', $enquiry->id)
        ->call('openAccount')
        ->assertHasErrors('newContactEmail');

    expect(User::whereEmail('kirsty@braemarjoinery.co.uk')->count())->toBe(1)
        ->and($enquiry->fresh()->becameClient())->toBeFalse();

    Notification::assertNotSentTo($existing, SetYourPassword::class);
});

test('an account cannot be opened twice from the same enquiry', function () {
    Notification::fake();

    $enquiry = Enquiry::factory()->create(['team_id' => Team::factory()->create()->id]);

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.enquiries')
        ->call('startAccount', $enquiry->id)
        ->assertStatus(409);
});

test('a promoted enquiry shows the account it became instead of offering again', function () {
    $team = Team::factory()->create(['name' => 'Petrie Plant Hire']);
    Enquiry::factory()->create(['name' => 'Alan Petrie', 'team_id' => $team->id, 'handled_at' => now()]);

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.enquiries')
        ->set('filter', 'all')
        ->assertSee('now a client')
        ->assertSee('opened as Petrie Plant Hire')
        ->assertDontSee('open an account');
});

test('the account form can be dismissed without opening anything', function () {
    $enquiry = Enquiry::factory()->create();

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.enquiries')
        ->call('startAccount', $enquiry->id)
        ->assertSet('opening', $enquiry->id)
        ->call('cancelAccount')
        ->assertSet('opening', null)
        ->assertSet('newBusiness', '');

    // The admin has a personal team of their own; nothing new was opened.
    expect(Team::whereName($enquiry->company)->exists())->toBeFalse();
});
