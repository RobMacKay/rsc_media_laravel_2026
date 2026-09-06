<?php

use App\Models\Enquiry;
use App\Models\User;
use App\Support\Honeypot;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/**
 * Fill in the registration form the way a person would.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function registrationFields(array $overrides = []): array
{
    return [
        'name' => 'Kirsty Munro',
        'business' => 'Braemar Joinery',
        'email' => 'kirsty@braemarjoinery.co.uk',
        'password' => 'wobbly-elephant-42',
        'password_confirmation' => 'wobbly-elephant-42',
        Honeypot::STAMP => app(Honeypot::class)->stamp(),
        ...$overrides,
    ];
}

test('a script that fills in the invisible field is turned away', function () {
    $this->travel(-1)->minutes();
    $fields = registrationFields([Honeypot::FIELD => 'http://cheap-backlinks.example']);
    $this->travelBack();

    $this->post(route('register.store'), $fields)
        ->assertSessionHasErrors(Honeypot::FIELD);

    expect(User::count())->toBe(0);
});

test('a real registration goes through', function () {
    $this->travel(-1)->minutes();
    $fields = registrationFields();
    $this->travelBack();

    $this->post(route('register.store'), $fields)->assertSessionHasNoErrors();

    expect(User::where('email', 'kirsty@braemarjoinery.co.uk')->exists())->toBeTrue();
});

test('a form that comes back instantly is turned away', function () {
    // No travelling: the stamp is made and posted in the same moment, which
    // nobody typing a registration form could manage.
    $this->post(route('register.store'), registrationFields())
        ->assertSessionHasErrors(Honeypot::FIELD);

    expect(User::count())->toBe(0);
});

test('a stamp that has been tampered with is refused', function () {
    $this->post(route('register.store'), registrationFields([
        Honeypot::STAMP => (string) now()->subHour()->getTimestamp(),
    ]))->assertSessionHasErrors(Honeypot::FIELD);

    expect(User::count())->toBe(0);
});

test('logging in is trapped but not timed, so a saved password still works', function () {
    $user = User::factory()->create(['password' => bcrypt('wobbly-elephant-42')]);

    // Straight in, no pause: a password manager is this fast.
    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'wobbly-elephant-42',
    ])->assertSessionHasNoErrors();

    $this->assertAuthenticated();
});

test('a script filling the invisible field cannot log in', function () {
    $user = User::factory()->create(['password' => bcrypt('wobbly-elephant-42')]);

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'wobbly-elephant-42',
        Honeypot::FIELD => 'http://cheap-backlinks.example',
    ])->assertSessionHasErrors(Honeypot::FIELD);

    $this->assertGuest();
});

test('the enquiry form ignores anything that fills its invisible field', function () {
    Notification::fake();

    Livewire::test('pages::home')
        ->set('name', 'Spam Bot')
        ->set('email', 'bot@example.com')
        ->set('message', 'Cheap backlinks.')
        ->set('websiteUrl', 'http://cheap-backlinks.example')
        ->call('send')
        ->assertHasErrors('websiteUrl');

    expect(Enquiry::count())->toBe(0);
    Notification::assertNothingSent();
});

test('the enquiry form refuses a submission nobody could have typed that fast', function () {
    Notification::fake();

    Livewire::test('pages::home')
        ->set('name', 'Spam Bot')
        ->set('email', 'bot@example.com')
        ->set('message', 'Cheap backlinks.')
        ->call('send')
        ->assertHasErrors('websiteUrl');

    expect(Enquiry::count())->toBe(0);
});

test('a person filling the enquiry form in at human speed gets through', function () {
    Notification::fake();
    User::factory()->admin()->create();

    $component = Livewire::test('pages::home')
        ->set('name', 'Jane Smith')
        ->set('email', 'jane@company.co.uk')
        ->set('message', 'Our booking form is a spreadsheet.');

    $this->travel(Honeypot::MIN_SECONDS + 1)->seconds();

    $component->call('send')->assertHasNoErrors();

    expect(Enquiry::count())->toBe(1);
});
