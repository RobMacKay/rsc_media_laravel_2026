<?php

use App\Actions\Clients\CreateClient;
use App\Enums\ClientAccess;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Notifications\SetYourPassword;
use DateTimeInterface;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

/**
 * Build the signed welcome link the emailed button points at.
 */
function welcomeLink(User $user, ?DateTimeInterface $expiresAt = null): string
{
    return URL::temporarySignedRoute(
        'password.set',
        $expiresAt ?? now()->addDays(SetYourPassword::VALID_FOR_DAYS),
        ['user' => $user->id],
    );
}

test('creating a client opens the business, its owner, and emails them', function () {
    Notification::fake();

    $admin = User::factory()->admin()->create(['name' => 'Ross Mackay']);

    $team = app(CreateClient::class)->handle(
        business: 'Braemar Joinery',
        contactName: 'Kirsty Munro',
        contactEmail: 'kirsty@braemarjoinery.co.uk',
        jobTitle: 'Office Manager',
        createdBy: $admin,
    );

    $user = User::where('email', 'kirsty@braemarjoinery.co.uk')->sole();
    $membership = $team->memberships()->where('user_id', $user->id)->sole();

    expect($team->name)->toBe('Braemar Joinery')
        ->and($membership->role)->toBe(TeamRole::Owner)
        ->and($membership->access)->toBe(ClientAccess::Full)
        ->and($membership->job_title)->toBe('Office Manager')
        ->and($user->must_set_password)->toBeTrue()
        ->and($user->current_team_id)->toBe($team->id);

    Notification::assertSentTo($user, SetYourPassword::class);
});

test('the account is created with a password nobody knows', function () {
    Notification::fake();

    app(CreateClient::class)->handle('Braemar Joinery', 'Kirsty Munro', 'kirsty@braemarjoinery.co.uk');

    $user = User::where('email', 'kirsty@braemarjoinery.co.uk')->sole();

    // Not blank, not guessable, and never shown to anyone: the welcome link is
    // the only way in until they choose their own.
    expect($user->password)->not->toBeEmpty()
        ->and(Hash::check('password', $user->password))->toBeFalse()
        ->and(Hash::check('', $user->password))->toBeFalse();
});

test('the welcome email carries a signed link that works', function () {
    Notification::fake();

    app(CreateClient::class)->handle('Braemar Joinery', 'Kirsty Munro', 'kirsty@braemarjoinery.co.uk');

    $user = User::where('email', 'kirsty@braemarjoinery.co.uk')->sole();

    Notification::assertSentTo($user, SetYourPassword::class, function (SetYourPassword $notification) use ($user) {
        $html = (string) $notification->toMail($user)->render();

        return str_contains($html, 'Set your password')
            && str_contains($html, 'signature=');
    });

    $this->get(welcomeLink($user))->assertOk()->assertSee('Set your password');
});

test('setting a password signs them in and lands them in their portal', function () {
    Notification::fake();

    app(CreateClient::class)->handle('Braemar Joinery', 'Kirsty Munro', 'kirsty@braemarjoinery.co.uk');

    $user = User::where('email', 'kirsty@braemarjoinery.co.uk')->sole();

    Livewire::test('pages::auth.set-password', ['user' => $user])
        ->set('password', 'a-long-enough-password')
        ->set('password_confirmation', 'a-long-enough-password')
        ->call('save')
        ->assertRedirect(route('client.dashboard'));

    $user->refresh();

    expect($user->must_set_password)->toBeFalse()
        ->and(Hash::check('a-long-enough-password', $user->password))->toBeTrue()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and(auth()->id())->toBe($user->id);
});

test('the welcome link stops working once the password is set', function () {
    Notification::fake();

    app(CreateClient::class)->handle('Braemar Joinery', 'Kirsty Munro', 'kirsty@braemarjoinery.co.uk');

    $user = User::where('email', 'kirsty@braemarjoinery.co.uk')->sole();
    $link = welcomeLink($user);

    $this->get($link)->assertOk();

    $user->forceFill(['must_set_password' => false])->save();

    // Still correctly signed, but there is nothing left to set.
    $this->get($link)->assertNotFound();
});

test('an unsigned or tampered welcome link is refused', function () {
    Notification::fake();

    app(CreateClient::class)->handle('Braemar Joinery', 'Kirsty Munro', 'kirsty@braemarjoinery.co.uk');

    $user = User::where('email', 'kirsty@braemarjoinery.co.uk')->sole();

    $this->get(route('password.set', ['user' => $user->id]))->assertForbidden();
    $this->get(welcomeLink($user).'x')->assertForbidden();
});

test('an expired welcome link is refused', function () {
    Notification::fake();

    app(CreateClient::class)->handle('Braemar Joinery', 'Kirsty Munro', 'kirsty@braemarjoinery.co.uk');

    $user = User::where('email', 'kirsty@braemarjoinery.co.uk')->sole();
    $link = welcomeLink($user, now()->subMinute());

    $this->get($link)->assertForbidden();
});

test('the studio can open a client account from the invoices panel', function () {
    Notification::fake();

    $component = Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.invoices')
        ->call('openForm')
        ->assertSet('formOpen', true)
        ->call('toggleAddClient')
        ->assertSet('addingClient', true)
        ->set('newBusiness', 'Cameron Roofing')
        ->set('newContactName', 'Morven Cameron')
        ->set('newContactEmail', 'morven@cameronroofing.test')
        ->call('createClient')
        ->assertHasNoErrors()
        ->assertSet('addingClient', false);

    $team = Team::where('name', 'Cameron Roofing')->sole();

    // The new client is selected, ready for the invoice being raised.
    $component->assertSet('teamId', $team->id);

    Notification::assertSentTo(User::where('email', 'morven@cameronroofing.test')->sole(), SetYourPassword::class);
});

test('the studio can open a client account from settings', function () {
    Notification::fake();

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.settings')
        ->call('toggleAddClient')
        ->set('newBusiness', 'Cameron Roofing')
        ->set('newContactName', 'Morven Cameron')
        ->set('newContactEmail', 'morven@cameronroofing.test')
        ->call('createClient')
        ->assertHasNoErrors()
        ->assertSet('addingClient', false)
        // Selected for editing, so its rates can be set straight away.
        ->assertSet('clientId', Team::where('name', 'Cameron Roofing')->value('id'));
});

test('a contact email already in use is refused, and nothing is created', function () {
    Notification::fake();

    User::factory()->create(['email' => 'taken@braemarjoinery.co.uk']);

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.invoices')
        ->call('toggleAddClient')
        ->set('newBusiness', 'Braemar Joinery')
        ->set('newContactName', 'Kirsty Munro')
        ->set('newContactEmail', 'taken@braemarjoinery.co.uk')
        ->call('createClient')
        ->assertHasErrors('newContactEmail');

    expect(Team::where('name', 'Braemar Joinery')->exists())->toBeFalse();

    Notification::assertNothingSent();
});

test('the invoice form opens in the panel and closes again', function () {
    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.invoices')
        ->assertSet('formOpen', false)
        ->call('openForm')
        ->assertSet('formOpen', true)
        ->call('closeForm')
        ->assertSet('formOpen', false);
});
