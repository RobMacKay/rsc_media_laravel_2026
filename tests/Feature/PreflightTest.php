<?php

use App\Jobs\PreflightPing;
use Illuminate\Support\Facades\Cache;

/**
 * Pretend this is the live site, where a missing worker is fatal rather than
 * just what a laptop looks like.
 */
function asProduction(): void
{
    app()->detectEnvironment(fn () => 'production');
}

/**
 * Leave the mark the scheduler leaves when it is running.
 */
function schedulerRan(?string $when = null): void
{
    Cache::forever('scheduler.last_run', $when ?? now()->toIso8601String());
}

test('the ping job answers where the check looks for it', function () {
    (new PreflightPing('abc123'))->handle();

    expect(Cache::get(PreflightPing::keyFor('abc123')))->not->toBeNull();
});

test('a stopped queue worker is reported as fatal', function () {
    schedulerRan();

    // The suite runs on the sync queue, where there is no worker to look for.
    config(['queue.default' => 'database']);

    $this->artisan('app:preflight', ['--wait' => 1])
        ->expectsOutputToContain('Nothing picked the job up')
        ->assertFailed();
});

test('a scheduler that has never run is reported', function () {
    Cache::forget('scheduler.last_run');

    $this->artisan('app:preflight', ['--skip-probe' => true])
        ->expectsOutputToContain('No sign it has ever run')
        ->assertFailed();
});

test('a scheduler that has stopped is reported', function () {
    schedulerRan(now()->subHour()->toIso8601String());

    $this->artisan('app:preflight', ['--skip-probe' => true])
        ->expectsOutputToContain('It has stopped')
        ->assertFailed();
});

test('a scheduler that ran a moment ago passes', function () {
    schedulerRan();

    $this->artisan('app:preflight', ['--skip-probe' => true])
        ->expectsOutputToContain('Last ran');
});

test('a mailer that sends nothing is only a warning off production, and fatal on it', function () {
    schedulerRan();
    config(['mail.default' => 'log', 'mail.from.address' => 'hello@rscmedia.co.uk']);

    $this->artisan('app:preflight', ['--skip-probe' => true])->assertSuccessful();

    asProduction();

    $this->artisan('app:preflight', ['--skip-probe' => true])
        ->expectsOutputToContain('does not send anything')
        ->assertFailed();
});

test('an unchanged from address is refused', function () {
    schedulerRan();
    config(['mail.default' => 'smtp', 'mail.from.address' => 'hello@example.com']);

    $this->artisan('app:preflight', ['--skip-probe' => true])
        ->expectsOutputToContain('MAIL_FROM_ADDRESS')
        ->assertFailed();
});

test('a live site on a plain http url is refused, because signed links would break', function () {
    schedulerRan();
    asProduction();
    config(['app.url' => 'http://rscmedia.co.uk', 'mail.default' => 'smtp', 'mail.from.address' => 'hello@rscmedia.co.uk']);

    $this->artisan('app:preflight', ['--skip-probe' => true])
        ->expectsOutputToContain('will 403 when opened')
        ->assertFailed();
});

test('a properly set up server passes', function () {
    schedulerRan();
    asProduction();

    config([
        'queue.default' => 'database',
        'app.url' => 'https://rscmedia.co.uk',
        'mail.default' => 'postmark',
        'mail.from.address' => 'hello@rscmedia.co.uk',
        'services.turnstile.site_key' => '1x00000000000000000000AA',
        'services.turnstile.secret_key' => '1x0000000000000000000000000000000AA',
    ]);

    $this->artisan('app:preflight', ['--skip-probe' => true])->assertSuccessful();
});

test('missing bot protection is flagged on the live site only', function () {
    schedulerRan();
    config(['services.turnstile.site_key' => null, 'services.turnstile.secret_key' => null]);

    $this->artisan('app:preflight', ['--skip-probe' => true])
        ->expectsOutputToContain('as expected outside production');

    asProduction();

    $this->artisan('app:preflight', ['--skip-probe' => true])
        ->expectsOutputToContain('the public forms are unguarded');
});

test('a live site left on the sync queue is refused', function () {
    schedulerRan();
    asProduction();

    $this->artisan('app:preflight', ['--skip-probe' => true])
        ->expectsOutputToContain('sync runs jobs in the request')
        ->assertFailed();
});
