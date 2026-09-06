<?php

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

test('email verification screen can be rendered', function () {
    $user = User::factory()->unverified()->create();

    $response = $this->actingAs($user)->get(route('verification.notice'));

    $response->assertOk();
});

test('email can be verified', function () {
    $user = User::factory()->unverified()->create();
    $team = $user->personalTeam();

    Event::fake();

    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1($user->email)],
    );

    $response = $this->actingAs($user)->get($verificationUrl);

    Event::assertDispatched(Verified::class);
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();

    $response->assertRedirect(route('client.dashboard', absolute: false).'?verified=1');
});

test('email is not verified with invalid hash', function () {
    $user = User::factory()->unverified()->create();

    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1('wrong-email')],
    );

    $this->actingAs($user)->get($verificationUrl);

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

test('already verified user visiting verification link is redirected without firing event again', function () {
    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);
    $team = $user->personalTeam();

    Event::fake();

    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1($user->email)],
    );

    $this->actingAs($user)->get($verificationUrl)
        ->assertRedirect(route('client.dashboard', absolute: false).'?verified=1');

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    Event::assertNotDispatched(Verified::class);
});

test('registering sends the confirmation link', function () {
    Notification::fake();

    $this->post(route('register.store'), typedByHand([
        'name' => 'Kirsty Munro',
        'business' => 'Braemar Joinery',
        'email' => 'kirsty@braemarjoinery.co.uk',
        'password' => 'wobbly-elephant-42',
        'password_confirmation' => 'wobbly-elephant-42',
    ]))->assertSessionHasNoErrors();

    $user = User::where('email', 'kirsty@braemarjoinery.co.uk')->sole();

    expect($user->hasVerifiedEmail())->toBeFalse();
    Notification::assertSentTo($user, VerifyEmail::class);
});

test('an unverified account cannot get at the portal until it has followed the link', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->get(route('client.dashboard'))
        ->assertRedirect(route('verification.notice'));

    $user->markEmailAsVerified();

    $this->actingAs($user)->get(route('client.dashboard'))->assertOk();
});

test('a studio-opened account is verified by setting its password, not by a second email', function () {
    Notification::fake();

    $user = User::factory()->unverified()->create(['must_set_password' => true]);

    $this->get(URL::temporarySignedRoute('password.set', now()->addWeek(), ['user' => $user->id]))
        ->assertOk();

    Notification::assertNotSentTo($user, VerifyEmail::class);
});
