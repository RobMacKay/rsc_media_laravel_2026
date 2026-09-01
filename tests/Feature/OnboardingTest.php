<?php

use App\Actions\Fortify\CreateNewUser;
use App\Enums\ClientAccess;
use App\Enums\ContactPreference;
use App\Enums\NotificationTopic;
use App\Enums\TeamRole;
use App\Models\StudioSetting;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Notifications\Teams\TeamInvitation as TeamInvitationNotification;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

test('a brand new account is sent to the welcome wizard', function () {
    $user = User::factory()->brandNew()->create();

    $this->actingAs($user)
        ->get(route('client.dashboard'))
        ->assertRedirect(route('onboarding'));
});

test('an established account goes straight to their portal', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('client.dashboard'))
        ->assertOk();
});

test('studio staff are never asked to onboard', function () {
    $admin = User::factory()->admin()->brandNew()->create();

    $this->actingAs($admin)
        ->get(route('client.dashboard'))
        ->assertOk();
});

test('the wizard saves the business onto the team', function () {
    $user = User::factory()->brandNew()->create();
    $team = $user->currentTeam;

    Livewire::actingAs($user)
        ->test('pages::onboarding')
        ->set('tradingName', 'Braemar Joinery')
        ->set('companyNumber', 'SC221084')
        ->set('address', "Unit 4, Lochy Industrial Estate\nFort William")
        ->set('vatNumber', 'GB334512908')
        ->set('billingEmail', 'accounts@braemarjoinery.co.uk')
        ->set('systems', "braemarjoinery.co.uk\n\n  Quote tracker  \n")
        ->call('saveCompany')
        ->assertHasNoErrors()
        ->assertSet('step', 'you');

    $team->refresh();

    expect($team->name)->toBe('Braemar Joinery')
        ->and($team->company_number)->toBe('SC221084')
        ->and($team->vat_number)->toBe('GB334512908')
        ->and($team->billing_email)->toBe('accounts@braemarjoinery.co.uk')
        // Blank lines and stray spacing are dropped, not stored.
        ->and($team->systems)->toBe(['braemarjoinery.co.uk', 'Quote tracker']);
});

test('what the studio looks after becomes an option when raising a ticket', function () {
    $user = User::factory()->create();
    $user->currentTeam->update(['systems' => ['braemarjoinery.co.uk', 'Quote tracker']]);

    Livewire::actingAs($user)
        ->test('pages::client.tickets')
        ->call('toggleForm')
        ->assertSee('Quote tracker');
});

test('the wizard saves this person and their email preferences', function () {
    $user = User::factory()->brandNew()->create();

    Livewire::actingAs($user)
        ->test('pages::onboarding')
        ->set('fullName', 'Kirsty Munro')
        ->set('jobTitle', 'Office Manager')
        ->set('phone', '01397 000000')
        ->call('chooseContact', ContactPreference::WhatsApp->value)
        ->call('toggleNotification', NotificationTopic::Studio->value)
        ->call('toggleNotification', NotificationTopic::Invoices->value)
        ->call('saveYou')
        ->assertHasNoErrors()
        ->assertSet('step', 'team');

    $user->refresh();

    expect($user->name)->toBe('Kirsty Munro')
        ->and($user->phone)->toBe('01397 000000')
        ->and($user->contact_preference)->toBe(ContactPreference::WhatsApp)
        // Studio notes start off and were turned on; invoices start on and were turned off.
        ->and($user->wantsEmailAbout(NotificationTopic::Studio))->toBeTrue()
        ->and($user->wantsEmailAbout(NotificationTopic::Invoices))->toBeFalse()
        ->and($user->wantsEmailAbout(NotificationTopic::Tickets))->toBeTrue()
        ->and($user->currentTeam->memberships()->where('user_id', $user->id)->value('job_title'))
        ->toBe('Office Manager');
});

test('the wizard cannot change the address someone signs in with', function () {
    $user = User::factory()->brandNew()->create(['email' => 'kirsty@braemarjoinery.co.uk']);

    Livewire::actingAs($user)
        ->test('pages::onboarding')
        ->set('email', 'someone@else.test')
        ->set('fullName', 'Kirsty Munro')
        ->call('saveYou')
        ->assertHasNoErrors();

    expect($user->fresh()->email)->toBe('kirsty@braemarjoinery.co.uk');
});

test('inviting someone from the wizard sends them an invite', function () {
    Notification::fake();

    $user = User::factory()->brandNew()->create();

    Livewire::actingAs($user)
        ->test('pages::onboarding')
        ->set('inviteName', 'Alan Munro')
        ->set('inviteEmail', 'alan@braemarjoinery.co.uk')
        ->set('inviteAccess', ClientAccess::Tickets->value)
        ->call('invite')
        ->assertHasNoErrors()
        ->assertSet('inviteEmail', '');

    Notification::assertSentOnDemand(TeamInvitationNotification::class);

    expect(TeamInvitation::where('email', 'alan@braemarjoinery.co.uk')->exists())->toBeTrue();
});

test('finishing marks the account onboarded and lets them into the portal', function () {
    $user = User::factory()->brandNew()->create();

    Livewire::actingAs($user)
        ->test('pages::onboarding')
        ->call('finish')
        ->assertRedirect(route('client.dashboard'));

    expect($user->fresh()->hasOnboarded())->toBeTrue();

    $this->actingAs($user->fresh())
        ->get(route('client.dashboard'))
        ->assertOk();
});

test('skipping counts as done, so the wizard stops asking', function () {
    $user = User::factory()->brandNew()->create();

    // "skip for now" is the same call as finishing: the wizard says everything
    // can be changed later, so it must not nag.
    Livewire::actingAs($user)->test('pages::onboarding')->call('finish');

    $this->actingAs($user->fresh())
        ->get(route('client.dashboard'))
        ->assertOk();
});

test('someone who joined on an invite is only asked about themselves', function () {
    $team = Team::factory()->create();
    $user = memberOf($team, ClientAccess::Tickets);
    $user->forceFill(['onboarded_at' => null])->save();

    $component = Livewire::actingAs($user)
        ->test('pages::onboarding')
        ->assertSet('step', 'you')
        ->assertDontSee('Your company');

    expect($component->instance()->steps)->toHaveCount(1);
});

test('an invited member cannot set up the business or invite others', function () {
    $team = Team::factory()->create(['name' => 'Braemar Joinery']);
    $user = memberOf($team, ClientAccess::Tickets);
    $user->forceFill(['onboarded_at' => null])->save();

    Livewire::actingAs($user)
        ->test('pages::onboarding')
        ->set('tradingName', 'Something Else')
        ->call('saveCompany')
        ->assertForbidden();

    expect($team->fresh()->name)->toBe('Braemar Joinery');
});

test('saving the only step finishes the wizard for an invited member', function () {
    $team = Team::factory()->create();
    $user = memberOf($team, ClientAccess::Tickets);
    $user->forceFill(['onboarded_at' => null])->save();

    Livewire::actingAs($user)
        ->test('pages::onboarding')
        ->set('fullName', 'Sam Docherty')
        ->call('saveYou')
        ->assertRedirect(route('client.dashboard'));

    expect($user->fresh()->hasOnboarded())->toBeTrue();
});

test('the intro video only appears when the studio has set one', function () {
    $user = User::factory()->brandNew()->create();

    Livewire::actingAs($user)
        ->test('pages::onboarding')
        ->assertDontSee('A quick hello from Ross');

    StudioSetting::current()->update(['welcome_video_url' => 'https://player.vimeo.com/video/76979871']);

    Livewire::actingAs($user)
        ->test('pages::onboarding')
        ->assertSee('A quick hello from Ross');
});

test('an invite carries the name and access the inviter chose', function () {
    Notification::fake();

    $user = User::factory()->brandNew()->create();

    Livewire::actingAs($user)
        ->test('pages::onboarding')
        ->call('go', 'team')
        ->set('inviteName', 'Alan Munro')
        ->set('inviteEmail', 'alan@braemarjoinery.co.uk')
        ->set('inviteAccess', ClientAccess::Full->value)
        ->call('invite')
        ->assertHasNoErrors()
        // The pending row names the person, rather than repeating their email.
        ->assertSee('Alan Munro');

    $invitation = TeamInvitation::where('email', 'alan@braemarjoinery.co.uk')->sole();

    expect($invitation->name)->toBe('Alan Munro')
        ->and($invitation->access)->toBe(ClientAccess::Full);
});

test('someone joining on an invite gets the access they were offered', function () {
    $team = Team::factory()->create();
    $owner = memberOf($team, ClientAccess::Full);

    $invitation = $team->invitations()->create([
        'email' => 'alan@braemarjoinery.co.uk',
        'name' => 'Alan Munro',
        'role' => TeamRole::Member,
        'access' => ClientAccess::Full,
        'invited_by' => $owner->id,
        'expires_at' => now()->addDays(7),
    ]);

    $joined = app(CreateNewUser::class)->create([
        'name' => 'Alan Munro',
        'email' => 'alan@braemarjoinery.co.uk',
        'password' => 'correcthorsebattery',
        'password_confirmation' => 'correcthorsebattery',
        'invitation' => $invitation->code,
    ]);

    expect($joined->accessFor())->toBe(ClientAccess::Full);
});

test('an invite from before the access field still joins on tickets only', function () {
    $team = Team::factory()->create();
    $owner = memberOf($team, ClientAccess::Full);

    $invitation = $team->invitations()->create([
        'email' => 'sam@braemarjoinery.co.uk',
        'role' => TeamRole::Member,
        'access' => null,
        'invited_by' => $owner->id,
        'expires_at' => now()->addDays(7),
    ]);

    $joined = app(CreateNewUser::class)->create([
        'name' => 'Sam Docherty',
        'email' => 'sam@braemarjoinery.co.uk',
        'password' => 'correcthorsebattery',
        'password_confirmation' => 'correcthorsebattery',
        'invitation' => $invitation->code,
    ]);

    expect($joined->accessFor())->toBe(ClientAccess::Tickets);
});
