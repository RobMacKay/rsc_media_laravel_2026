<?php

use App\Actions\Sites\CheckSite;
use App\Enums\ClientAccess;
use App\Enums\SiteStatus;
use App\Models\Site;
use App\Models\SiteCheck;
use App\Models\StudioSetting;
use App\Models\Team;
use App\Notifications\SiteIsBackUp;
use App\Notifications\SiteIsDown;
use App\Support\Sites\CertificateInspector;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Support\FakeCertificateInspector;

/**
 * Answer every outbound request with the given status.
 */
function siteReplies(int $status): void
{
    Http::fake(['*' => Http::response('<html>hello</html>', $status)]);
}

/**
 * Swap the real TLS handshake for a fixed answer.
 */
function certificateExpires(int $inDays): void
{
    app()->instance(CertificateInspector::class, FakeCertificateInspector::expiring(now()->addDays($inDays)));
}

beforeEach(function () {
    certificateExpires(60);
});

test('a client can add a site to watch', function () {
    $team = Team::factory()->create();
    $user = memberOf($team, ClientAccess::Full);

    Livewire::actingAs($user)
        ->test('pages::client.health')
        ->call('toggleForm')
        ->set('name', 'Main website')
        // Typed without a scheme, the way people actually type a domain.
        ->set('url', 'braemarjoinery.co.uk')
        ->call('addSite')
        ->assertHasNoErrors();

    $site = $team->sites()->sole();

    expect($site->name)->toBe('Main website')
        ->and($site->url)->toBe('https://braemarjoinery.co.uk')
        ->and($site->host)->toBe('braemarjoinery.co.uk')
        ->and($site->status)->toBe(SiteStatus::Unknown);
});

test('the same site cannot be added twice', function () {
    $team = Team::factory()->create();
    $user = memberOf($team, ClientAccess::Full);
    Site::factory()->for($team)->create(['host' => 'braemarjoinery.co.uk']);

    Livewire::actingAs($user)
        ->test('pages::client.health')
        ->set('name', 'Again')
        ->set('url', 'https://braemarjoinery.co.uk/')
        ->call('addSite')
        ->assertHasErrors('url');

    expect($team->sites()->count())->toBe(1);
});

test('a client gets five sites by default and cannot exceed the allowance', function () {
    $team = Team::factory()->create();
    $user = memberOf($team, ClientAccess::Full);

    expect($team->effectiveSiteLimit(StudioSetting::current()))->toBe(5);

    Site::factory()->count(5)->for($team)->create();

    Livewire::actingAs($user)
        ->test('pages::client.health')
        ->assertSet('hasRoom', false)
        ->set('name', 'One too many')
        ->set('url', 'https://example.com')
        ->call('addSite')
        ->assertStatus(422);

    expect($team->sites()->count())->toBe(5);
});

test('the studio can give one client a bigger allowance', function () {
    $team = Team::factory()->create(['site_limit' => 12]);

    expect($team->effectiveSiteLimit(StudioSetting::current()))->toBe(12);
});

test('a site on the private network is refused', function () {
    $team = Team::factory()->create();
    $user = memberOf($team, ClientAccess::Full);

    // Otherwise the monitor is a way to probe whatever the server can reach.
    foreach (['http://127.0.0.1', 'http://localhost', 'http://192.168.0.1', 'http://169.254.169.254'] as $url) {
        Livewire::actingAs($user)
            ->test('pages::client.health')
            ->set('name', 'Sneaky')
            ->set('url', $url)
            ->call('addSite')
            ->assertHasErrors('url');
    }

    expect($team->sites()->count())->toBe(0);
});

test('a check records the result and marks the site up', function () {
    siteReplies(200);

    $site = Site::factory()->create(['url' => 'https://braemarjoinery.co.uk']);

    $check = app(CheckSite::class)->handle($site);

    $site->refresh();

    expect($check->status)->toBe(SiteStatus::Up)
        ->and($check->http_status)->toBe(200)
        ->and($site->status)->toBe(SiteStatus::Up)
        ->and($site->consecutive_failures)->toBe(0)
        ->and($site->last_up_at)->not->toBeNull()
        ->and($site->ssl_valid)->toBeTrue()
        ->and($site->sslDaysLeft())->toBe(60);
});

test('a redirect still counts as up', function () {
    Http::fake(['*' => Http::response('', 301)]);

    $site = Site::factory()->create();

    expect(app(CheckSite::class)->handle($site)->status)->toBe(SiteStatus::Up);
});

test('a server error counts as down and is explained', function () {
    siteReplies(503);

    $site = Site::factory()->create();

    $check = app(CheckSite::class)->handle($site);

    expect($check->status)->toBe(SiteStatus::Down)
        ->and($check->http_status)->toBe(503)
        ->and($check->error)->toContain('503')
        ->and($site->fresh()->consecutive_failures)->toBe(1);
});

test('one blip does not email anyone', function () {
    Notification::fake();
    siteReplies(503);

    $team = Team::factory()->create();
    memberOf($team, ClientAccess::Full);
    $site = Site::factory()->for($team)->create();

    app(CheckSite::class)->handle($site);

    Notification::assertNothingSent();
});

test('two failures in a row emails the client, once', function () {
    Notification::fake();
    siteReplies(503);

    $team = Team::factory()->create();
    $user = memberOf($team, ClientAccess::Full);
    $site = Site::factory()->for($team)->create();

    app(CheckSite::class)->handle($site);
    app(CheckSite::class)->handle($site);

    Notification::assertSentTo($user, SiteIsDown::class);

    // Still down on the next two checks: no more email.
    app(CheckSite::class)->handle($site);
    app(CheckSite::class)->handle($site);

    Notification::assertSentToTimes($user, SiteIsDown::class, 1);
});

test('coming back up sends one all-clear, and arms the next warning', function () {
    Notification::fake();

    // A sequence, not repeated Http::fake() calls: a second fake keeps the
    // first matching stub, so the site would never appear to recover.
    Http::fakeSequence()
        ->push('', 503)
        ->push('', 503)
        ->push('', 200)
        ->push('', 503)
        ->push('', 503);

    $team = Team::factory()->create();
    $user = memberOf($team, ClientAccess::Full);
    $site = Site::factory()->for($team)->create();

    app(CheckSite::class)->handle($site);
    app(CheckSite::class)->handle($site);

    Notification::assertSentToTimes($user, SiteIsDown::class, 1);

    app(CheckSite::class)->handle($site);

    Notification::assertSentTo($user, SiteIsBackUp::class);

    expect($site->fresh()->down_notified_at)->toBeNull()
        ->and($site->fresh()->status)->toBe(SiteStatus::Up);

    // A fresh outage warns again rather than staying quiet.
    app(CheckSite::class)->handle($site);
    app(CheckSite::class)->handle($site);

    Notification::assertSentToTimes($user, SiteIsDown::class, 2);
});

test('a site that cannot be reached at all is down, not an exception', function () {
    Http::fake(fn () => throw new ConnectionException('Could not resolve host'));

    $site = Site::factory()->create();

    $check = app(CheckSite::class)->handle($site);

    expect($check->status)->toBe(SiteStatus::Down)
        ->and($check->http_status)->toBeNull()
        ->and($check->error)->toContain('Could not resolve host');
});

test('an expiring certificate is flagged', function () {
    siteReplies(200);
    certificateExpires(10);

    $site = Site::factory()->create();

    app(CheckSite::class)->handle($site);

    $site->refresh();

    expect($site->sslExpiringSoon())->toBeTrue()
        ->and($site->sslLabel())->toBe('Expires in 10 days');
});

test('a site with no certificate says so', function () {
    siteReplies(200);
    app()->instance(CertificateInspector::class, FakeCertificateInspector::missing());

    $site = Site::factory()->create();

    app(CheckSite::class)->handle($site);

    expect($site->fresh()->ssl_valid)->toBeFalse()
        ->and($site->fresh()->sslLabel())->toBe('No valid certificate');
});

test('a site served over plain http is never called secure', function () {
    siteReplies(200);

    $site = Site::factory()->create(['url' => 'http://braemarjoinery.co.uk']);

    app(CheckSite::class)->handle($site);

    expect($site->fresh()->ssl_valid)->toBeFalse();
});

test('uptime is worked out from the last month of checks', function () {
    $site = Site::factory()->create();

    SiteCheck::factory()->count(9)->for($site)->create(['checked_at' => now()->subDays(2)]);
    SiteCheck::factory()->for($site)->down()->create(['checked_at' => now()->subDays(2)]);
    // Older than the window, so it should not drag the figure down.
    SiteCheck::factory()->count(10)->for($site)->down()->create(['checked_at' => now()->subDays(60)]);

    expect($site->uptimePercent())->toBe(90.0);
});

test('the scheduled command checks every active site', function () {
    siteReplies(200);

    Site::factory()->count(2)->create();
    Site::factory()->create(['is_active' => false]);

    $this->artisan('sites:check')->assertSuccessful();

    expect(SiteCheck::count())->toBe(2);
});

test('a client can download the log for their own site', function () {
    $team = Team::factory()->create();
    $user = memberOf($team, ClientAccess::Full);
    $site = Site::factory()->for($team)->create(['host' => 'braemarjoinery.co.uk']);

    SiteCheck::factory()->for($site)->create(['checked_at' => now()->subHour()]);
    SiteCheck::factory()->for($site)->down()->create(['checked_at' => now()]);

    $response = $this->actingAs($user)->get(route('client.health.log', $site));

    $response->assertOk()
        ->assertDownload('braemarjoinery.co.uk-log-'.now()->format('Y-m-d').'.csv');

    $csv = $response->streamedContent();

    expect($csv)->toContain('checked_at,status,http_status')
        ->and($csv)->toContain('down')
        ->and(substr_count(trim($csv), "\n"))->toBe(2);
});

test('a client cannot download another business\'s log', function () {
    $ours = Team::factory()->create();
    $theirs = Team::factory()->create();

    $user = memberOf($ours, ClientAccess::Full);
    $site = Site::factory()->for($theirs)->create();

    $this->actingAs($user)->get(route('client.health.log', $site))->assertNotFound();
});

test('view-only people cannot add or remove sites', function () {
    $team = Team::factory()->create();
    $user = memberOf($team, ClientAccess::View);
    $site = Site::factory()->for($team)->create();

    Livewire::actingAs($user)
        ->test('pages::client.health')
        ->set('name', 'Nope')
        ->set('url', 'https://example.com')
        ->call('addSite')
        ->assertForbidden();

    Livewire::actingAs($user)
        ->test('pages::client.health')
        ->call('removeSite', $site->id)
        ->assertForbidden();
});

test('a client only ever sees their own sites', function () {
    $ours = Team::factory()->create();
    $theirs = Team::factory()->create();

    $user = memberOf($ours, ClientAccess::Full);

    Site::factory()->for($ours)->create(['name' => 'Our website']);
    Site::factory()->for($theirs)->create(['name' => 'Their website']);

    $this->actingAs($user)
        ->get(route('client.health'))
        ->assertOk()
        ->assertSee('Our website')
        ->assertDontSee('Their website');
});
