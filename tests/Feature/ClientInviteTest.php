<?php

use App\Actions\Clients\InviteClient;
use App\Enums\ClientAccess;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Notifications\Clients\PortalInvitation;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * A client brought over from Invoice Ninja: a business with history and nobody in it.
 */
function importedClient(string $name, ?string $email = null): Team
{
    return Team::factory()->create(['name' => $name, 'billing_email' => $email]);
}

/**
 * The settings screen, as the studio admin.
 */
function settingsScreen(): Testable
{
    return Livewire::actingAs(User::factory()->admin()->create())->test('pages::admin.settings');
}

test('the panel lists only clients with nobody in the portal', function () {
    $waiting = importedClient('Kintail Joinery & Sons', 'accounts@kintail.test');
    $alreadyIn = importedClient('Corrie Studio', 'iain@corrie.test');
    memberOf($alreadyIn, ClientAccess::Full);

    // Asserted on the list itself rather than the rendered page: every client
    // also appears in the rate-overrides chips further down, so assertDontSee
    // would be testing the wrong thing.
    $screen = settingsScreen()->assertSee('client_portal_access');

    expect($screen->instance()->clientsWithoutAccess->pluck('name')->all())
        ->toBe([$waiting->name]);
});

test('inviting a client emails only the clients ticked', function () {
    Notification::fake();

    $invited = importedClient('Kintail Joinery & Sons', 'accounts@kintail.test');
    $notInvited = importedClient('Tayside Opportunities', 'hello@tayside.test');

    settingsScreen()
        ->set('inviting.'.$invited->id, true)
        ->call('reviewInvites')
        ->assertSee('accounts@kintail.test')
        ->call('sendInvites')
        ->assertHasNoErrors();

    Notification::assertSentOnDemandTimes(PortalInvitation::class, 1);

    expect($invited->invitations()->count())->toBe(1)
        ->and($notInvited->invitations()->count())->toBe(0);
});

test('nothing is sent until the list has been reviewed', function () {
    Notification::fake();

    $team = importedClient('Kintail Joinery & Sons', 'accounts@kintail.test');

    settingsScreen()
        ->set('inviting.'.$team->id, true)
        ->assertSet('confirmingInvites', false);

    Notification::assertNothingSent();

    expect(TeamInvitation::count())->toBe(0);
});

test('an invitation carries full access, so they can see their invoices', function () {
    Notification::fake();

    $team = importedClient('Kintail Joinery & Sons', 'accounts@kintail.test');

    settingsScreen()
        ->set('inviting.'.$team->id, true)
        ->call('reviewInvites')
        ->call('sendInvites');

    $invitation = $team->invitations()->sole();

    expect($invitation->access)->toBe(ClientAccess::Full)
        ->and($invitation->email)->toBe('accounts@kintail.test')
        ->and($invitation->expires_at->toDateString())
        ->toBe(now()->addDays(InviteClient::VALID_FOR_DAYS)->toDateString());
});

test('a client with no address on file cannot be sent to', function () {
    Notification::fake();

    $team = importedClient('Lochside Design');

    settingsScreen()
        ->set('inviting.'.$team->id, true)
        ->call('reviewInvites')
        ->assertSet('confirmingInvites', false);

    Notification::assertNothingSent();
});

test('an address typed over the one on file is the one used', function () {
    Notification::fake();

    $team = importedClient('Kintail Joinery & Sons', 'stale@kintail.test');

    settingsScreen()
        ->set('inviting.'.$team->id, true)
        ->set('inviteEmails.'.$team->id, 'elspeth@kintail.test')
        ->call('reviewInvites')
        ->call('sendInvites');

    expect($team->invitations()->sole()->email)->toBe('elspeth@kintail.test');
});

test('a second invitation to the same address is refused', function () {
    Notification::fake();

    $team = importedClient('Kintail Joinery & Sons', 'accounts@kintail.test');

    settingsScreen()
        ->set('inviting.'.$team->id, true)
        ->call('reviewInvites')
        ->call('sendInvites');

    settingsScreen()
        ->set('inviting.'.$team->id, true)
        ->call('reviewInvites')
        ->call('sendInvites')
        ->assertHasErrors('inviteEmails.'.$team->id);

    expect($team->invitations()->count())->toBe(1);
});

test('the invite controls do not submit the settings form', function () {
    // The panel used to sit inside <form wire:submit="save">, so every invite
    // button also saved the settings and its toast masked the real one.
    $markup = file_get_contents(resource_path('views/pages/admin/settings.blade.php'));

    $formOpensAt = strpos($markup, 'wire:submit="save"');
    $panelOpensAt = strpos($markup, 'client_portal_access');

    expect($panelOpensAt)->toBeLessThan($formOpensAt);

    foreach (['sendInvites', 'cancelInvites', 'reviewInvites'] as $action) {
        expect($markup)->toContain('type="button" wire:click="'.$action.'"');
    }
});

test('the email says who it is from, how long it lasts, and carries the link', function () {
    $team = importedClient('Kintail Joinery & Sons', 'accounts@kintail.test');

    $invitation = app(InviteClient::class)->handle(
        team: $team,
        email: 'accounts@kintail.test',
        invitedBy: User::factory()->admin()->create(),
    );

    $mail = (new PortalInvitation($invitation))->toMail($invitation);
    $rendered = $mail->render();

    $this->assertStringContainsString('ready to set up', (string) $mail->subject);
    $this->assertStringContainsString('Kintail Joinery &amp; Sons', $rendered);
    $this->assertStringContainsString(route('login', ['invitation' => $invitation->code]), $rendered);

    // Counted from now to expiry; the other way round rendered as
    // "good for -13.999763228287 days" in a real send.
    $this->assertStringContainsString('good for '.InviteClient::VALID_FOR_DAYS.' days', $rendered);
});

test('a client cannot reach the settings screen', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('admin.settings'))
        ->assertForbidden();
});

test('an invited client who registers lands with their invoices visible', function () {
    $team = importedClient('Kintail Joinery & Sons', 'accounts@kintail.test');

    $invitation = app(InviteClient::class)->handle(
        team: $team,
        email: 'accounts@kintail.test',
        invitedBy: User::factory()->admin()->create(),
    );

    $this->post(route('register.store'), typedByHand([
        'name' => 'Elspeth Ross',
        'email' => 'accounts@kintail.test',
        'password' => 'kintail-password-1',
        'password_confirmation' => 'kintail-password-1',
        'invitation' => $invitation->code,
    ]))->assertRedirect();

    $user = User::query()->where('email', 'accounts@kintail.test')->sole();

    expect($user->belongsToTeam($team))->toBeTrue()
        ->and($user->accessFor($team))->toBe(ClientAccess::Full)
        ->and($user->accessFor($team)->canSeeBilling())->toBeTrue();
});

test('an invite redeemed under a different address is refused', function () {
    $team = importedClient('Kintail Joinery & Sons', 'accounts@kintail.test');

    $invitation = app(InviteClient::class)->handle(
        team: $team,
        email: 'accounts@kintail.test',
        invitedBy: User::factory()->admin()->create(),
    );

    // A forwarded invitation must not let somebody else into a client's
    // invoice history under their own address.
    $this->post(route('register.store'), typedByHand([
        'name' => 'Someone Else',
        'email' => 'someone@elsewhere.test',
        'password' => 'elsewhere-password-1',
        'password_confirmation' => 'elsewhere-password-1',
        'invitation' => $invitation->code,
    ]))->assertSessionHasErrors('invitation');

    expect(User::query()->where('email', 'someone@elsewhere.test')->exists())->toBeFalse()
        ->and($team->fresh()->members()->count())->toBe(0);
});

test('a client who already has an account keeps full access when accepting', function () {
    $team = importedClient('Kintail Joinery & Sons', 'accounts@kintail.test');

    $existing = User::factory()->create(['email' => 'accounts@kintail.test']);

    $invitation = app(InviteClient::class)->handle(
        team: $team,
        email: 'accounts@kintail.test',
        invitedBy: User::factory()->admin()->create(),
    );

    $this->actingAs($existing);

    Livewire::test('pages::teams.pending-invitations-modal')
        ->call('acceptInvitation', $invitation->code);

    // Without the access being carried over, the membership falls to the
    // column default of tickets-only and they see no invoices at all.
    expect($existing->fresh()->accessFor($team))->toBe(ClientAccess::Full);
});
