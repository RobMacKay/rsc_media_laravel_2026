<?php

use App\Actions\Billing\RaiseRecurringInvoices;
use App\Enums\Currency;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\RecurringInvoice;
use App\Models\StudioSetting;
use App\Models\Team;

use function Pest\Laravel\travelTo;

/**
 * The action behind both the daily run and the admin screen.
 */
function recurringRaiser(): RaiseRecurringInvoices
{
    return new RaiseRecurringInvoices(StudioSetting::current());
}

test('a schedule raises its invoice once its day has come round', function () {
    travelTo('2026-10-17 08:00');

    $schedule = RecurringInvoice::factory()->onDay(17)->create([
        'note' => 'Hosting and maintenance',
        'amount' => 20,
    ]);

    $raised = recurringRaiser()->handle();

    expect($raised)->toHaveCount(1);

    $invoice = $raised->first();

    expect($invoice->amount)->toBe(20.0)
        ->and($invoice->note)->toBe('Hosting and maintenance — October 2026')
        ->and($invoice->recurring_invoice_id)->toBe($schedule->id)
        ->and($invoice->status)->toBe(InvoiceStatus::Sent);
});

test('a schedule waits until its day rather than billing on the first', function () {
    travelTo('2026-10-16 08:00');

    RecurringInvoice::factory()->onDay(17)->create();

    expect(recurringRaiser()->handle())->toBeEmpty();

    $this->assertDatabaseCount('invoices', 0);
});

test('a schedule set past the end of a short month bills on its last day', function () {
    travelTo('2026-02-28 08:00');

    $schedule = RecurringInvoice::factory()->onDay(31)->create();

    expect($schedule->billingDateIn(now())->toDateString())->toBe('2026-02-28')
        ->and(recurringRaiser()->handle())->toHaveCount(1);
});

test('a schedule that has already billed this month does not bill again', function () {
    travelTo('2026-10-17 08:00');

    $schedule = RecurringInvoice::factory()->onDay(17)->create();

    recurringRaiser()->handle();
    recurringRaiser()->handle();
    recurringRaiser()->handle();

    expect($schedule->invoices()->count())->toBe(1);
});

test('a schedule bills again the following month', function () {
    travelTo('2026-10-17 08:00');

    $schedule = RecurringInvoice::factory()->onDay(17)->create();

    recurringRaiser()->handle();

    travelTo('2026-11-17 08:00');

    recurringRaiser()->handle();

    expect($schedule->invoices()->count())->toBe(2)
        ->and($schedule->invoices()->pluck('note')->all())
        ->toBe([
            'Hosting and maintenance — October 2026',
            'Hosting and maintenance — November 2026',
        ]);
});

test('a schedule switched off raises nothing', function () {
    travelTo('2026-10-17 08:00');

    RecurringInvoice::factory()->onDay(17)->inactive()->create();

    expect(recurringRaiser()->handle())->toBeEmpty();
});

test('a schedule that has ended raises nothing', function () {
    travelTo('2026-10-17 08:00');

    RecurringInvoice::factory()->onDay(17)->endedOn('2026-09-30')->create();

    expect(recurringRaiser()->handle())->toBeEmpty();
});

test('a schedule that has not started yet raises nothing', function () {
    travelTo('2026-10-17 08:00');

    RecurringInvoice::factory()->onDay(17)->create(['starts_on' => '2026-11-01']);

    expect(recurringRaiser()->handle())->toBeEmpty();
});

test('a discounted arrangement bills the discounted amount and says what it gave away', function () {
    travelTo('2026-10-01 08:00');

    // The charity pays £6 of a £25 hosting bill.
    RecurringInvoice::factory()->create([
        'note' => 'Website hosting',
        'amount' => 6,
        'discount' => 19,
    ]);

    $invoice = recurringRaiser()->handle()->first();

    expect($invoice->amount)->toBe(6.0)
        ->and($invoice->discount)->toBe(19.0)
        ->and($invoice->subtotal())->toBe(25.0);
});

test('an arrangement bills in the client own currency', function () {
    travelTo('2026-10-01 08:00');

    $team = Team::factory()->create(['currency' => Currency::EUR]);
    RecurringInvoice::factory()->for($team)->create(['amount' => 4800]);

    $invoice = recurringRaiser()->handle()->first();

    expect($invoice->currency)->toBe(Currency::EUR)
        ->and($invoice->money($invoice->amount))->toBe('€4,800');
});

test('a record only arrangement writes a settled record with nothing to chase', function () {
    travelTo('2026-10-01 08:00');

    RecurringInvoice::factory()->recordOnly()->create([
        'note' => '3 days at £175',
        'amount' => 525,
    ]);

    $invoice = recurringRaiser()->handle()->first();

    expect($invoice->record_only)->toBeTrue()
        ->and($invoice->status)->toBe(InvoiceStatus::Paid)
        ->and($invoice->paid_to_date)->toBe(525.0)
        ->and($invoice->paid_at)->not->toBeNull()
        ->and($invoice->number)->toStartWith('REC-')
        ->and($invoice->reminderDue())->toBeNull();
});

test('an arrangement with no money on it raises nothing', function () {
    travelTo('2026-10-01 08:00');

    RecurringInvoice::factory()->create(['amount' => 0]);

    expect(recurringRaiser()->handle())->toBeEmpty();
});

test('a recurring invoice does not eat into a fixed project price', function () {
    travelTo('2026-10-01 08:00');

    $team = Team::factory()->create();
    $project = $team->projects()->create([
        'reference' => 'PRJ-001',
        'title' => 'Website rebuild',
        'agreed_value' => 6600,
    ]);

    RecurringInvoice::factory()->for($team)->create(['amount' => 20]);

    recurringRaiser()->handle();

    expect($project->fresh()->balanceToInvoice())->toBe(6600);
});

test('the monthly plan run and the recurring run do not bill each other clients', function () {
    travelTo('2026-10-01 08:00');

    $plan = Plan::factory()->create(['price' => 120]);
    $onPlan = Team::factory()->create(['plan_id' => $plan->id]);
    $onArrangement = Team::factory()->create();

    RecurringInvoice::factory()->for($onArrangement)->create(['amount' => 20]);

    recurringRaiser()->handle();

    expect($onPlan->invoices()->count())->toBe(0)
        ->and($onArrangement->invoices()->count())->toBe(1)
        ->and($onArrangement->invoices()->sole()->type)->toBe(InvoiceType::AdHoc);
});

test('the command lists what is due without raising it', function () {
    travelTo('2026-10-17 08:00');

    $team = Team::factory()->create(['name' => 'Tayside Opportunities']);
    RecurringInvoice::factory()->for($team)->onDay(17)->create(['amount' => 20]);

    $this->artisan('invoices:raise-recurring', ['--pretend' => true])
        ->assertSuccessful();

    $this->assertDatabaseCount('invoices', 0);
});

test('the command raises what is due and says how many', function () {
    travelTo('2026-10-17 08:00');

    RecurringInvoice::factory()->onDay(17)->create(['amount' => 20]);

    $this->artisan('invoices:raise-recurring')
        ->expectsOutputToContain('Raised 1 recurring invoice.')
        ->assertSuccessful();

    expect(Invoice::count())->toBe(1);
});

test('the command says there is nothing due when nothing is', function () {
    travelTo('2026-10-16 08:00');

    RecurringInvoice::factory()->onDay(17)->create();

    $this->artisan('invoices:raise-recurring')
        ->expectsOutputToContain('Nothing due')
        ->assertSuccessful();
});
