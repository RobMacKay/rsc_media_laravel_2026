<?php

use App\Enums\ClientAccess;
use App\Models\Enquiry;
use App\Models\Plan;
use App\Models\Team;
use App\Models\User;
use App\Notifications\NewEnquiry;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

test('the homepage lists the plans on offer', function () {
    Plan::factory()->create(['name' => 'Care & Support', 'sort_order' => 1]);
    Plan::factory()->retired()->create(['name' => 'Legacy Bronze']);

    $this->get(route('home'))
        ->assertOk()
        ->assertSee('Care &amp; Support', escape: false)
        ->assertDontSee('Legacy Bronze');
});

test('an enquiry is stored and the studio is told about it', function () {
    Notification::fake();

    $ross = User::factory()->admin()->create();
    User::factory()->create();

    Livewire::test('pages::home')
        ->set('name', 'Jane Smith')
        ->set('email', 'jane@company.co.uk')
        ->set('company', 'Company Ltd')
        ->set('topic', 'existing')
        ->set('message', 'Our booking form drops the phone number.')
        ->call('send')
        ->assertHasNoErrors()
        ->assertSet('sent', true);

    $enquiry = Enquiry::sole();

    expect($enquiry->name)->toBe('Jane Smith')
        ->and($enquiry->topicLabel())->toBe('Existing system');

    Notification::assertSentTo($ross, NewEnquiry::class);
    Notification::assertCount(1);
});

test('an enquiry needs a name, an email and a message', function () {
    Livewire::test('pages::home')
        ->set('name', '')
        ->set('email', 'not-an-email')
        ->set('message', '')
        ->call('send')
        ->assertHasErrors(['name', 'email', 'message']);

    expect(Enquiry::count())->toBe(0);
});

test('an unknown topic is rejected', function () {
    Livewire::test('pages::home')
        ->set('name', 'Jane Smith')
        ->set('email', 'jane@company.co.uk')
        ->set('topic', 'something-else')
        ->set('message', 'Hello.')
        ->call('send')
        ->assertHasErrors('topic');
});

test('guests are offered a login link', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertSee('client_login')
        ->assertSee(route('login'));
});

test('a signed-in client is offered their client area instead', function () {
    $user = memberOf(Team::factory()->create(), ClientAccess::Full);

    $this->actingAs($user)
        ->get(route('home'))
        ->assertOk()
        ->assertSee('client_area')
        ->assertDontSee('client_login')
        ->assertSee(route('client.dashboard'));
});

test('a signed-in admin is offered the admin portal instead', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('home'))
        ->assertOk()
        ->assertDontSee('client_login')
        ->assertSee(route('admin.queue'));
});

test('guest-only pages send a signed-in visitor to their own portal', function () {
    $client = memberOf(Team::factory()->create(), ClientAccess::Full);
    $admin = User::factory()->admin()->create();

    foreach (['login', 'register', 'password.request'] as $route) {
        $this->actingAs($client)->get(route($route))->assertRedirect(route('client.dashboard'));
        $this->actingAs($admin)->get(route($route))->assertRedirect(route('admin.queue'));
    }
});
