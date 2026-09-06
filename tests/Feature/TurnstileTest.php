<?php

use App\Models\Enquiry;
use App\Models\User;
use App\Notifications\NewEnquiry;
use App\Support\Turnstile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/**
 * Switch bot protection on with Cloudflare's own test keys.
 */
function turnstileOn(): void
{
    config([
        'services.turnstile.site_key' => '1x00000000000000000000AA',
        'services.turnstile.secret_key' => '1x0000000000000000000000000000000AA',
    ]);
}

/**
 * Fake what Cloudflare says about a token.
 */
function turnstileSays(bool $success, array $errors = []): void
{
    Http::fake([Turnstile::VERIFY_URL => Http::response([
        'success' => $success,
        'error-codes' => $errors,
    ])]);
}

test('it is off until both keys are configured', function () {
    config(['services.turnstile.site_key' => null, 'services.turnstile.secret_key' => null]);
    expect(app(Turnstile::class)->enabled())->toBeFalse();

    // A site key on its own would draw a widget nothing ever checks.
    config(['services.turnstile.site_key' => '1x00000000000000000000AA']);
    expect(app(Turnstile::class)->enabled())->toBeFalse();

    config(['services.turnstile.secret_key' => '1x0000000000000000000000000000000AA']);
    expect(app(Turnstile::class)->enabled())->toBeTrue();
});

test('nothing is asked of anyone while it is switched off', function () {
    Http::fake();
    Notification::fake();

    Livewire::test('pages::home')
        ->assertDontSee('challenges.cloudflare.com')
        ->set('name', 'Jane Smith')
        ->set('email', 'jane@company.co.uk')
        ->set('message', 'Our booking form is a spreadsheet.')
        ->call('send')
        ->assertHasNoErrors();

    expect(Enquiry::count())->toBe(1);
    Http::assertNothingSent();
});

test('a token Cloudflare accepts lets the enquiry through', function () {
    turnstileOn();
    turnstileSays(true);
    Notification::fake();

    $admin = User::factory()->admin()->create();

    Livewire::test('pages::home')
        ->set('name', 'Jane Smith')
        ->set('email', 'jane@company.co.uk')
        ->set('message', 'Our booking form is a spreadsheet.')
        ->set('turnstileToken', 'a-token-from-the-widget')
        ->call('send')
        ->assertHasNoErrors();

    expect(Enquiry::count())->toBe(1);
    Notification::assertSentTo($admin, NewEnquiry::class);

    Http::assertSent(fn ($request) => $request['response'] === 'a-token-from-the-widget'
        && $request['secret'] === '1x0000000000000000000000000000000AA'
        && filled($request['idempotency_key']));
});

test('a token Cloudflare refuses stops the enquiry, and nothing is recorded', function () {
    turnstileOn();
    turnstileSays(false, ['invalid-input-response']);
    Notification::fake();

    Livewire::test('pages::home')
        ->set('name', 'Spam Bot')
        ->set('email', 'bot@example.com')
        ->set('message', 'Cheap backlinks.')
        ->set('turnstileToken', 'forged')
        ->call('send')
        ->assertHasErrors('turnstileToken');

    expect(Enquiry::count())->toBe(0);
    Notification::assertNothingSent();
});

test('a submission with no token at all is refused without asking Cloudflare', function () {
    turnstileOn();
    Http::fake();

    Livewire::test('pages::home')
        ->set('name', 'Spam Bot')
        ->set('email', 'bot@example.com')
        ->set('message', 'Cheap backlinks.')
        ->call('send')
        ->assertHasErrors('turnstileToken');

    expect(Enquiry::count())->toBe(0);
    Http::assertNothingSent();
});

test('an outage at Cloudflare does not take the enquiry form down with it', function () {
    turnstileOn();
    Http::fake([Turnstile::VERIFY_URL => Http::response('', 503)]);
    Notification::fake();

    Livewire::test('pages::home')
        ->set('name', 'Jane Smith')
        ->set('email', 'jane@company.co.uk')
        ->set('message', 'Our booking form is a spreadsheet.')
        ->set('turnstileToken', 'a-token-from-the-widget')
        ->call('send')
        ->assertHasNoErrors();

    expect(Enquiry::count())->toBe(1);
});

test('registering needs a token once it is switched on', function () {
    turnstileOn();
    turnstileSays(false, ['invalid-input-response']);

    $this->post(route('register.store'), [
        'name' => 'Kirsty Munro',
        'business' => 'Braemar Joinery',
        'email' => 'kirsty@braemarjoinery.co.uk',
        'password' => 'wobbly-elephant-42',
        'password_confirmation' => 'wobbly-elephant-42',
        'cf-turnstile-response' => 'forged',
    ])->assertSessionHasErrors('cf-turnstile-response');

    expect(User::where('email', 'kirsty@braemarjoinery.co.uk')->exists())->toBeFalse();
});

test('a real visitor can still register', function () {
    turnstileOn();
    turnstileSays(true);

    $this->post(route('register.store'), [
        'name' => 'Kirsty Munro',
        'business' => 'Braemar Joinery',
        'email' => 'kirsty@braemarjoinery.co.uk',
        'password' => 'wobbly-elephant-42',
        'password_confirmation' => 'wobbly-elephant-42',
        'cf-turnstile-response' => 'a-token-from-the-widget',
    ])->assertSessionHasNoErrors();

    expect(User::where('email', 'kirsty@braemarjoinery.co.uk')->exists())->toBeTrue();
});

test('the password reset form is guarded too', function () {
    turnstileOn();
    turnstileSays(false, ['invalid-input-response']);
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.email'), [
        'email' => $user->email,
        'cf-turnstile-response' => 'forged',
    ])->assertSessionHasErrors('cf-turnstile-response');

    Notification::assertNothingSent();
});

test('the widget is put on the public forms, and only while it is switched on', function () {
    turnstileOn();

    foreach ([route('login'), route('register'), route('password.request')] as $url) {
        $this->get($url)
            ->assertSee('challenges.cloudflare.com')
            // interaction-only is what keeps the box off the page for everyone
            // Cloudflare is happy about, which is nearly everybody.
            ->assertSee('interaction-only');
    }

    $this->get('/')->assertSee('challenges.cloudflare.com');

    config(['services.turnstile.site_key' => null, 'services.turnstile.secret_key' => null]);

    $this->get(route('login'))->assertDontSee('challenges.cloudflare.com');
    $this->get('/')->assertDontSee('challenges.cloudflare.com');
});

test('signed-in traffic is never challenged', function () {
    turnstileOn();
    Http::fake();

    $user = User::factory()->create();

    $this->actingAs($user)->post(route('logout'))->assertRedirect();

    Http::assertNothingSent();
});
