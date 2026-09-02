<?php

use App\Actions\Billing\ChaseInvoices;
use App\Enums\ClientAccess;
use App\Enums\InvoiceReminder;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\StudioSetting;
use App\Models\Team;
use App\Models\User;
use App\Notifications\InvoiceReminderNotification;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/**
 * An unpaid invoice that fell due the given number of days ago.
 */
function invoiceDue(int $daysAgo, ?Team $team = null, InvoiceStatus $status = InvoiceStatus::Sent): Invoice
{
    return Invoice::factory()->for($team ?? Team::factory())->create([
        'status' => $status,
        'due_on' => now()->subDays($daysAgo),
        'amount' => 1000,
    ]);
}

test('the stage ladder climbs and then stops', function () {
    expect(InvoiceReminder::dueAfter(-5))->toBeNull()
        ->and(InvoiceReminder::dueAfter(-3))->toBe(InvoiceReminder::DueSoon)
        ->and(InvoiceReminder::dueAfter(0))->toBe(InvoiceReminder::DueSoon)
        ->and(InvoiceReminder::dueAfter(1))->toBe(InvoiceReminder::JustOverdue)
        ->and(InvoiceReminder::dueAfter(7))->toBe(InvoiceReminder::Chasing)
        ->and(InvoiceReminder::dueAfter(14))->toBe(InvoiceReminder::FinalNotice)
        // Nothing past a fortnight: a person picks it up from there.
        ->and(InvoiceReminder::dueAfter(90))->toBe(InvoiceReminder::FinalNotice);
});

test('an invoice past its date is marked overdue', function () {
    $late = invoiceDue(3);
    $notYet = invoiceDue(-3);
    $paid = invoiceDue(9, status: InvoiceStatus::Paid);

    app(ChaseInvoices::class)->markOverdue();

    expect($late->fresh()->status)->toBe(InvoiceStatus::Overdue)
        ->and($notYet->fresh()->status)->toBe(InvoiceStatus::Sent)
        ->and($paid->fresh()->status)->toBe(InvoiceStatus::Paid);
});

test('overdue is derived, so it is right before the nightly run', function () {
    $invoice = invoiceDue(2);

    // Still stored as Sent, because the command has not run.
    expect($invoice->status)->toBe(InvoiceStatus::Sent)
        ->and($invoice->isOverdue())->toBeTrue()
        ->and($invoice->daysPastDue())->toBe(2);
});

test('each stage sends once, however many times the command runs', function () {
    Notification::fake();

    $team = Team::factory()->create(['billing_email' => 'accounts@braemarjoinery.co.uk']);
    $invoice = invoiceDue(1, $team);

    app(ChaseInvoices::class)->sendReminders();
    app(ChaseInvoices::class)->sendReminders();
    app(ChaseInvoices::class)->sendReminders();

    Notification::assertSentOnDemandTimes(InvoiceReminderNotification::class, 1);

    expect($invoice->fresh()->reminder_stage)->toBe(InvoiceReminder::JustOverdue);
});

test('the next stage goes out when it comes round', function () {
    Notification::fake();

    $team = Team::factory()->create(['billing_email' => 'accounts@braemarjoinery.co.uk']);
    $invoice = invoiceDue(1, $team);

    app(ChaseInvoices::class)->sendReminders();
    expect($invoice->fresh()->reminder_stage)->toBe(InvoiceReminder::JustOverdue);

    $invoice->update(['due_on' => now()->subDays(7)]);
    app(ChaseInvoices::class)->sendReminders();
    expect($invoice->fresh()->reminder_stage)->toBe(InvoiceReminder::Chasing);

    $invoice->update(['due_on' => now()->subDays(14)]);
    app(ChaseInvoices::class)->sendReminders();
    expect($invoice->fresh()->reminder_stage)->toBe(InvoiceReminder::FinalNotice);

    // A month later, still nothing more.
    $invoice->update(['due_on' => now()->subDays(40)]);
    app(ChaseInvoices::class)->sendReminders();

    Notification::assertSentOnDemandTimes(InvoiceReminderNotification::class, 3);

    expect($invoice->fresh()->remindersExhausted())->toBeTrue();
});

test('an invoice found long overdue does not replay the earlier stages', function () {
    Notification::fake();

    $team = Team::factory()->create(['billing_email' => 'accounts@braemarjoinery.co.uk']);
    invoiceDue(30, $team);

    app(ChaseInvoices::class)->sendReminders();

    Notification::assertSentOnDemand(InvoiceReminderNotification::class, function ($notification) {
        return $notification->stage === InvoiceReminder::FinalNotice;
    });

    Notification::assertSentOnDemandTimes(InvoiceReminderNotification::class, 1);
});

test('a nudge goes out before it is even due', function () {
    Notification::fake();

    $team = Team::factory()->create(['billing_email' => 'accounts@braemarjoinery.co.uk']);
    $invoice = invoiceDue(-2, $team);

    app(ChaseInvoices::class)->sendReminders();

    expect($invoice->fresh()->reminder_stage)->toBe(InvoiceReminder::DueSoon);

    Notification::assertSentOnDemand(InvoiceReminderNotification::class);
});

test('nothing is chased before the nudge window opens', function () {
    Notification::fake();

    invoiceDue(-10, Team::factory()->create(['billing_email' => 'a@b.test']));

    app(ChaseInvoices::class)->sendReminders();

    Notification::assertNothingSent();
});

test('paid and draft invoices are left alone', function () {
    Notification::fake();

    $team = Team::factory()->create(['billing_email' => 'a@b.test']);
    invoiceDue(9, $team, InvoiceStatus::Paid);
    invoiceDue(9, $team, InvoiceStatus::Draft);

    app(ChaseInvoices::class)->sendReminders();

    Notification::assertNothingSent();
});

test('a muted invoice is not chased', function () {
    Notification::fake();

    $team = Team::factory()->create(['billing_email' => 'a@b.test']);
    $invoice = invoiceDue(9, $team);
    $invoice->update(['reminders_paused_at' => now()]);

    app(ChaseInvoices::class)->sendReminders();

    Notification::assertNothingSent();

    expect($invoice->fresh()->reminder_stage)->toBeNull();
});

test('turning reminders off stops the emails but still marks things overdue', function () {
    Notification::fake();

    StudioSetting::current()->update(['invoice_reminders' => false]);

    $team = Team::factory()->create(['billing_email' => 'a@b.test']);
    $invoice = invoiceDue(9, $team);

    app(ChaseInvoices::class)->markOverdue();
    app(ChaseInvoices::class)->sendReminders();

    Notification::assertNothingSent();

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Overdue);
});

test('only people who can see invoices are chased', function () {
    Notification::fake();

    // No billing address on the account, so it falls back to the people on it.
    $team = Team::factory()->create(['billing_email' => null]);
    $owner = memberOf($team, ClientAccess::Full);
    memberOf($team, ClientAccess::Tickets);
    memberOf($team, ClientAccess::View);

    invoiceDue(1, $team);

    app(ChaseInvoices::class)->sendReminders();

    Notification::assertSentOnDemand(InvoiceReminderNotification::class, function ($notification, $channels, $notifiable) use ($owner) {
        return $notifiable->routes['mail'] === [$owner->email];
    });
});

test('the billing address wins over the people on the account', function () {
    Notification::fake();

    $team = Team::factory()->create(['billing_email' => 'accounts@braemarjoinery.co.uk']);
    memberOf($team, ClientAccess::Full);

    invoiceDue(1, $team);

    app(ChaseInvoices::class)->sendReminders();

    Notification::assertSentOnDemand(InvoiceReminderNotification::class, function ($notification, $channels, $notifiable) {
        return $notifiable->routes['mail'] === ['accounts@braemarjoinery.co.uk'];
    });
});

test('an account with nobody to email is skipped rather than erroring', function () {
    Notification::fake();

    $team = Team::factory()->create(['billing_email' => null]);
    $invoice = invoiceDue(1, $team);

    app(ChaseInvoices::class)->sendReminders();

    Notification::assertNothingSent();

    // Left unstaged, so it goes out the moment somebody can receive it.
    expect($invoice->fresh()->reminder_stage)->toBeNull();
});

test('the reminder email says what it is chasing', function () {
    $team = Team::factory()->create(['name' => 'Braemar Joinery']);
    $invoice = invoiceDue(7, $team);
    $invoice->update(['note' => 'Site rebuild — balance on completion']);

    $mail = (new InvoiceReminderNotification($invoice, InvoiceReminder::Chasing))
        ->toMail(new AnonymousNotifiable);

    $html = (string) $mail->render();

    expect($mail->subject)->toContain('a week overdue')
        ->and($html)->toContain($invoice->number)
        ->and($html)->toContain('Site rebuild')
        ->and($html)->toContain('View the invoice');
});

test('the command marks, sends and can be rehearsed', function () {
    Notification::fake();

    $team = Team::factory()->create(['billing_email' => 'a@b.test']);
    $invoice = invoiceDue(1, $team);

    $this->artisan('invoices:chase --pretend')->assertSuccessful();

    // A rehearsal changes nothing.
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Sent)
        ->and($invoice->fresh()->reminder_stage)->toBeNull();

    Notification::assertNothingSent();

    $this->artisan('invoices:chase')->assertSuccessful();

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Overdue)
        ->and($invoice->fresh()->reminder_stage)->toBe(InvoiceReminder::JustOverdue);
});

test('the studio can mute and unmute one invoice from the list', function () {
    $invoice = invoiceDue(3);

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.invoices')
        ->call('toggleReminders', $invoice->id);

    expect($invoice->fresh()->reminders_paused_at)->not->toBeNull();

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.invoices')
        ->call('toggleReminders', $invoice->id);

    expect($invoice->fresh()->reminders_paused_at)->toBeNull();
});

test('the overdue tile counts what is actually late', function () {
    $team = Team::factory()->create();

    // Late but never touched by the nightly command.
    invoiceDue(4, $team);
    invoiceDue(-4, $team);

    $component = Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.invoices');

    $overdue = collect($component->instance()->money)->firstWhere('label', 'overdue');

    expect($overdue['sub'])->toContain('1 invoice past its date');
});

test('a draft is never late, however old it gets', function () {
    // It has not gone to the client, so there is nothing for them to be late
    // paying — and it must not appear in the overdue tile or get chased.
    $draft = invoiceDue(30, status: InvoiceStatus::Draft);

    expect($draft->isOverdue())->toBeFalse()
        ->and($draft->reminderDue())->toBeNull();

    $component = Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.invoices');

    $overdue = collect($component->instance()->money)->firstWhere('label', 'overdue');

    expect($overdue['sub'])->toContain('Nothing late');
});
