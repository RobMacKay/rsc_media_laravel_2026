<?php

use App\Enums\ClientAccess;
use App\Models\StudioSetting;
use App\Models\Team;
use App\Models\User;

test('anyone can read the cookie notice without signing in', function () {
    $this->get(route('legal.cookies'))
        ->assertOk()
        ->assertSee('What this site stores')
        // The two that are always set, named so somebody can look for them.
        ->assertSee(config('session.cookie'))
        ->assertSee('XSRF-TOKEN')
        ->assertSee('No Google Analytics', false);
});

test('the cookie notice names the session length actually configured', function () {
    config(['session.lifetime' => 45]);

    $this->get(route('legal.cookies'))->assertSee('45 minutes of inactivity');
});

test('anyone can read the privacy notice without signing in', function () {
    StudioSetting::current()->update([
        'company_name' => 'RSC Media Ltd',
        'company_number' => 'SC512347',
        'email' => 'info@rscmedia.co.uk',
    ]);

    $this->get(route('legal.privacy'))
        ->assertOk()
        ->assertSee('RSC Media Ltd')
        ->assertSee('SC512347')
        ->assertSee('info@rscmedia.co.uk')
        ->assertSee('ico.org.uk');
});

test('both notices are reachable from the marketing site', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertSee(route('legal.privacy'))
        ->assertSee(route('legal.cookies'));
});

test('both notices are reachable from inside the client area', function () {
    $team = Team::factory()->create();

    $this->actingAs(memberOf($team, ClientAccess::Full))
        ->get(route('client.dashboard'))
        ->assertOk()
        ->assertSee(route('legal.privacy'))
        ->assertSee(route('legal.cookies'));
});

test('the privacy notice is reachable from the sign-in screen', function () {
    $this->get(route('login'))->assertOk()->assertSee(route('legal.privacy'));
});

test('a vimeo welcome video is embedded with tracking off', function () {
    $settings = StudioSetting::current();

    $settings->welcome_video_url = 'https://player.vimeo.com/video/76979871';
    expect($settings->welcomeVideoEmbedUrl())->toBe('https://player.vimeo.com/video/76979871?dnt=1');

    // Existing query strings are kept, and it is not added twice.
    $settings->welcome_video_url = 'https://player.vimeo.com/video/76979871?h=abc';
    expect($settings->welcomeVideoEmbedUrl())->toBe('https://player.vimeo.com/video/76979871?h=abc&dnt=1');

    $settings->welcome_video_url = 'https://player.vimeo.com/video/76979871?dnt=1';
    expect($settings->welcomeVideoEmbedUrl())->toBe('https://player.vimeo.com/video/76979871?dnt=1');
});

test('a youtube welcome video is embedded from the no-cookie domain', function () {
    $settings = StudioSetting::current();

    $settings->welcome_video_url = 'https://www.youtube.com/embed/abc123';

    expect($settings->welcomeVideoEmbedUrl())->toBe('https://www.youtube-nocookie.com/embed/abc123');
});

test('no video set means no iframe at all', function () {
    $settings = StudioSetting::current();

    $settings->welcome_video_url = '';
    expect($settings->welcomeVideoEmbedUrl())->toBeNull();

    $settings->welcome_video_url = null;
    expect($settings->welcomeVideoEmbedUrl())->toBeNull();
});

test('the onboarding video is served with tracking off', function () {
    StudioSetting::current()->update(['welcome_video_url' => 'https://player.vimeo.com/video/76979871']);

    $this->actingAs(User::factory()->brandNew()->create())
        ->get(route('onboarding'))
        ->assertOk()
        ->assertSee('player.vimeo.com/video/76979871?dnt=1', false);
});
