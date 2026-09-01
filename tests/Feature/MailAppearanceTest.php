<?php

use App\Enums\ClientAccess;
use App\Enums\TeamRole;
use App\Models\StudioSetting;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Notifications\Teams\TeamInvitation as TeamInvitationNotification;
use Illuminate\Mail\Markdown;
use Illuminate\Notifications\AnonymousNotifiable;

/**
 * Render the invitation email, which is a plain MailMessage and so goes
 * through the same markdown layout as everything else we send.
 */
function renderedMail(): string
{
    $team = Team::factory()->create(['name' => 'Braemar Joinery']);
    $inviter = memberOf($team, ClientAccess::Full);

    $invitation = TeamInvitation::create([
        'team_id' => $team->id,
        'email' => 'alan@braemarjoinery.co.uk',
        'name' => 'Alan Munro',
        'role' => TeamRole::Member,
        'access' => ClientAccess::Tickets,
        'invited_by' => $inviter->id,
        'expires_at' => now()->addDays(7),
    ]);

    return (string) (new TeamInvitationNotification($invitation))
        ->toMail(new AnonymousNotifiable)
        ->render();
}

test('the mail theme is the RSC one, not Laravel default', function () {
    expect(config('mail.markdown.theme'))->toBe('rsc')
        ->and(config('mail.markdown.paths'))->toContain(resource_path('views/vendor/mail'));
});

test('email is painted in the light palette from the site', function () {
    $html = renderedMail();

    expect($html)
        // The accent green and the ink/panel backgrounds, inlined.
        ->toContain('#0d7d63')
        ->toContain('#eef4f2')
        ->toContain('#fbfdfc')
        // And nothing left over from the stock Laravel theme.
        ->not->toContain('#3869d4')
        ->not->toContain('laravel.com/img/notification-logo');
});

test('the button label is legible against the button', function () {
    $html = renderedMail();

    preg_match('/<a[^>]*class="button button-primary"[^>]*style="([^"]*)"/', $html, $matches);

    $style = $matches[1] ?? '';

    // `.inner-body a` is two classes deep, so without a more specific rule the
    // link colour wins and the label is painted the same green as the pill.
    expect($style)->toContain('background-color: #0d7d63')
        ->and($style)->toContain('color: #f4fbf9');
});

test('email is signed off with the studio details from settings', function () {
    StudioSetting::current()->update([
        'company_name' => 'RSC Media Ltd',
        'company_number' => 'SC512347',
        'address' => "Unit 4, Bridgend Works\nDunkeld\nPH8 0AA",
        'email' => 'info@rscmedia.co.uk',
        'website' => 'rscmedia.co.uk',
    ]);

    $html = renderedMail();

    expect($html)
        ->toContain('RSC Media Ltd')
        ->toContain('SC512347')
        ->toContain('Unit 4, Bridgend Works, Dunkeld, PH8 0AA')
        ->toContain('mailto:info@rscmedia.co.uk')
        ->toContain('https://rscmedia.co.uk');
});

test('the footer leaves out details the studio has not filled in', function () {
    StudioSetting::current()->update([
        'company_name' => 'RSC Media Ltd',
        'company_number' => null,
        'address' => null,
        'email' => null,
        'website' => null,
    ]);

    $html = renderedMail();

    expect($html)->toContain('RSC Media Ltd')
        // No stray separators where the missing pieces would have been.
        ->and($html)->not->toContain('RSC Media Ltd ·');
});

test('the wordmark links back to the site rather than to Laravel', function () {
    $html = renderedMail();

    expect($html)->toContain(route('home'))
        ->toContain('RSC');
});

test('the plain text version still carries the studio details', function () {
    $team = Team::factory()->create();
    $inviter = memberOf($team, ClientAccess::Full);

    $invitation = TeamInvitation::create([
        'team_id' => $team->id,
        'email' => 'alan@braemarjoinery.co.uk',
        'role' => TeamRole::Member,
        'invited_by' => $inviter->id,
        'expires_at' => now()->addDays(7),
    ]);

    StudioSetting::current()->update(['company_name' => 'RSC Media Ltd', 'email' => 'info@rscmedia.co.uk']);

    $mail = (new TeamInvitationNotification($invitation))->toMail(new AnonymousNotifiable);

    $text = app(Markdown::class)
        ->renderText($mail->markdown ?? 'notifications::email', $mail->data());

    expect((string) $text)
        ->toContain('RSC Media Ltd')
        ->toContain('info@rscmedia.co.uk')
        ->toContain('has invited you to join');
});
