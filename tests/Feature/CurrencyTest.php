<?php

use App\Actions\Billing\RaiseInvoice;
use App\Enums\ClientAccess;
use App\Enums\Currency;
use App\Enums\InvoiceType;
use App\Models\Plan;
use App\Models\Proposal;
use App\Models\StudioSetting;
use App\Models\Team;
use App\Models\User;
use App\Support\Money;
use Livewire\Livewire;

test('a currency formats with its symbol, and dollars say which dollars', function () {
    expect(Currency::GBP->format(1200))->toBe('£1,200')
        ->and(Currency::EUR->format(1200))->toBe('€1,200')
        ->and(Currency::USD->format(1200))->toBe('$1,200 USD')
        ->and(Currency::CAD->format(1200))->toBe('$1,200 CAD')
        ->and(Currency::GBP->format(1200.5, 2))->toBe('£1,200.50');
});

test('a client bills in sterling unless it is told otherwise', function () {
    expect(Team::factory()->create()->currency)->toBe(Currency::GBP)
        ->and((new Team)->currency)->toBe(Currency::GBP);
});

test('an invoice keeps the currency it was raised in when the client moves', function () {
    $team = Team::factory()->create(['currency' => Currency::EUR]);

    $invoice = app(RaiseInvoice::class)->handle(
        team: $team,
        type: InvoiceType::AdHoc,
        note: 'Some work',
        amount: 900,
    );

    expect($invoice->currency)->toBe(Currency::EUR)
        ->and($invoice->money($invoice->amount))->toBe('€900');

    // Moving the client to another currency must not restate what has already
    // gone out the door.
    $team->update(['currency' => Currency::USD]);

    expect($invoice->fresh()->currency)->toBe(Currency::EUR);
});

test('totals across clients stay honest about spanning currencies', function () {
    $rows = collect([
        ['amount' => 1000.0, 'currency' => Currency::GBP],
        ['amount' => 200.0, 'currency' => Currency::GBP],
        ['amount' => 500.0, 'currency' => Currency::USD],
    ]);

    $total = fn ($rows) => Money::total($rows, fn ($row) => $row['amount'], fn ($row) => $row['currency']);

    expect($total($rows))->toBe('£1,200 + $500 USD')
        ->and($total($rows->take(2)))->toBe('£1,200')
        ->and($total(collect()))->toBe('£0');
});

test('the budget brackets on the project form follow the client currency', function () {
    expect(Proposal::budgets(Currency::GBP))->toContain('Under £1k')
        ->and(Proposal::budgets(Currency::EUR))->toContain('€1k–€3k')
        ->and(Proposal::budgets(Currency::USD))->toContain('$3k–$7k');
});

test('a client sees their own currency on an invoice they can open', function () {
    $team = Team::factory()->create(['currency' => Currency::CAD]);
    $user = memberOf($team, ClientAccess::Full);

    $invoice = app(RaiseInvoice::class)->handle(
        team: $team,
        type: InvoiceType::Deposit,
        note: 'Deposit',
        amount: 2400,
    );

    $this->actingAs($user)
        ->get(route('client.invoices.show', $invoice->number))
        ->assertOk()
        ->assertSee('$2,400.00 CAD');
});

test('an admin can set the currency a client is billed in', function () {
    $studio = StudioSetting::current();
    $team = Team::factory()->create();
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.settings')
        ->call('selectClient', $team->id)
        ->call('chooseCurrency', Currency::EUR->value)
        ->call('save')
        ->assertHasNoErrors();

    expect($team->fresh()->currency)->toBe(Currency::EUR)
        ->and($team->fresh()->money($team->effectiveHourRate($studio)))
        ->toStartWith('€');
});

test('saving the studio settings with no client selected leaves currencies alone', function () {
    $team = Team::factory()->create(['currency' => Currency::USD]);
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.settings')
        ->call('save')
        ->assertHasNoErrors();

    expect($team->fresh()->currency)->toBe(Currency::USD);
});

test('plan prices are shown to a client in their own currency', function () {
    $plan = Plan::factory()->create(['price' => 180, 'is_live' => true]);
    $team = Team::factory()->create(['plan_id' => $plan->id, 'currency' => Currency::EUR]);
    $user = memberOf($team, ClientAccess::Full);

    $this->actingAs($user)
        ->get(route('client.plan'))
        ->assertOk()
        ->assertSee('€180')
        ->assertDontSee('£180');
});

test('an invoice pdf is rendered in the currency it was raised in', function () {
    $team = Team::factory()->create(['currency' => Currency::EUR]);
    $user = memberOf($team, ClientAccess::Full);

    $invoice = app(RaiseInvoice::class)->handle(
        team: $team,
        type: InvoiceType::AdHoc,
        note: 'Extra page build',
        amount: 1200,
    );

    $this->actingAs($user)
        ->get(route('client.invoices.pdf', $invoice->number))
        ->assertOk()
        ->assertDownload($invoice->number.'.pdf');
});

test('the settings screen warns when a studio rate is reused under another currency', function () {
    $team = Team::factory()->create(['currency' => Currency::EUR, 'hour_rate' => 80, 'day_rate' => null]);
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.settings')
        ->call('selectClient', $team->id)
        ->assertSet('client.currency', Currency::EUR->value)
        ->assertSee('nothing converts it')
        // The hourly rate is set for this client, so only the day rate is at risk.
        ->assertDontSee('nothing converts them');
});

test('a sterling client gets no conversion warning', function () {
    $team = Team::factory()->create(['currency' => Currency::GBP, 'day_rate' => null]);
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.settings')
        ->call('selectClient', $team->id)
        ->assertDontSee('nothing converts');
});
