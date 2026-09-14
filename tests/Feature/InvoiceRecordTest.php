<?php

use App\Actions\Attachments\StoreAttachment;
use App\Actions\Billing\ChaseInvoices;
use App\Actions\Billing\RaiseInvoice;
use App\Enums\ClientAccess;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\StudioSetting;
use App\Models\Team;
use App\Models\User;
use App\Notifications\InvoiceReminderNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/**
 * Record the agency day-rate work the studio gets a remittance advice for.
 */
function agencyRecord(?Team $team = null, float $amount = 525): Invoice
{
    return (new RaiseInvoice(StudioSetting::current()))->record(
        team: $team ?? Team::factory()->create(['name' => 'Deveron Creative Partners']),
        note: '3 days at £175 — week ending 5 September 2026',
        amount: $amount,
        externalReference: 'AGY9900112233445566',
    );
}

test('a record is written as settled, with the agency reference kept', function () {
    $invoice = agencyRecord();

    expect($invoice->record_only)->toBeTrue()
        ->and($invoice->status)->toBe(InvoiceStatus::Paid)
        ->and($invoice->amount)->toBe(525.0)
        ->and($invoice->paid_to_date)->toBe(525.0)
        ->and($invoice->paid_at)->not->toBeNull()
        ->and($invoice->external_reference)->toBe('AGY9900112233445566')
        ->and($invoice->vat_rate)->toBe(0.0);
});

test('a record charges no VAT even while the studio is registered', function () {
    StudioSetting::current()->update(['vat_registered' => true, 'vat_number' => 'GB123456789']);

    $invoice = agencyRecord();

    expect($invoice->vat_rate)->toBe(0.0)
        ->and($invoice->total())->toBe(525.0);
});

test('a record takes its own number rather than one of the studio own', function () {
    $record = agencyRecord();

    expect($record->number)->toBe('REC-0001')
        ->and(Invoice::nextNumber())->toBe('RSC-0001');

    expect(agencyRecord()->number)->toBe('REC-0002');
});

test('a record is never chased, however old it is', function () {
    Notification::fake();

    $team = Team::factory()->create(['billing_email' => 'accounts@agency.test']);
    $record = agencyRecord($team);
    $record->update(['due_on' => now()->subMonths(3)]);

    app(ChaseInvoices::class)->sendReminders();

    Notification::assertNothingSent();

    expect($record->fresh()->reminder_stage)->toBeNull()
        ->and($record->fresh()->reminderDue())->toBeNull();
});

test('a record is never marked overdue by the nightly run', function () {
    $record = agencyRecord();
    $record->update(['due_on' => now()->subMonths(3)]);

    app(ChaseInvoices::class)->markOverdue();

    expect($record->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($record->fresh()->isOverdue())->toBeFalse();
});

test('a record still unpaid is not overdue and not chased', function () {
    Notification::fake();

    $team = Team::factory()->create(['billing_email' => 'accounts@agency.test']);

    // A remittance that has been advised but not yet landed.
    $record = agencyRecord($team);
    $record->update([
        'status' => InvoiceStatus::Sent,
        'paid_at' => null,
        'paid_to_date' => 0,
        'due_on' => now()->subMonth(),
    ]);

    app(ChaseInvoices::class)->sendReminders();

    expect($record->fresh()->isOverdue())->toBeFalse()
        ->and($record->fresh()->reminderDue())->toBeNull()
        ->and($record->fresh()->remindersExhausted())->toBeFalse();

    Notification::assertNotSentTo(new AnonymousNotifiable, InvoiceReminderNotification::class);
});

test('a record is left out of what is outstanding', function () {
    $team = Team::factory()->create();

    $owed = Invoice::factory()->for($team)->create(['status' => InvoiceStatus::Sent]);
    $record = agencyRecord($team);
    $record->update(['status' => InvoiceStatus::Sent, 'paid_at' => null]);

    expect($team->invoices()->outstanding()->pluck('id')->all())->toBe([$owed->id]);
});

test('a record is kept out of the client invoice list', function () {
    $team = Team::factory()->create();
    $invoice = Invoice::factory()->for($team)->create();
    agencyRecord($team);

    $user = memberOf($team, ClientAccess::Full);

    Livewire\Livewire::actingAs($user)
        ->test('pages::client.invoices')
        ->assertSee($invoice->number)
        ->assertDontSee('REC-0001');
});

test('a record has no invoice for the client to open', function () {
    $team = Team::factory()->create();
    $record = agencyRecord($team);

    $this->actingAs(memberOf($team, ClientAccess::Full));

    Livewire\Livewire::test('pages::client.invoice', ['invoice' => $record])
        ->assertNotFound();
});

test('a record returns 404 rather than being rendered as an RSC invoice PDF', function () {
    // The happy path is covered in InvoiceDocumentTest; what matters here is
    // that a record cannot be dressed up in the studio's own letterhead.
    $team = Team::factory()->create();
    $record = agencyRecord($team);

    $this->actingAs(memberOf($team, ClientAccess::Full))
        ->get(route('client.invoices.pdf', $record->number))
        ->assertNotFound();
});

test('the remittance advice can be filed against the record', function () {
    Storage::fake('local');

    $record = agencyRecord();
    $studio = User::factory()->admin()->create();

    $attachment = app(StoreAttachment::class)->handle(
        attachable: $record,
        file: UploadedFile::fake()->create('RemittanceAdvice.pdf', 12, 'application/pdf'),
        uploader: $studio,
        sharedWithClient: false,
    );

    expect($record->attachments()->count())->toBe(1)
        ->and($attachment->name)->toBe('RemittanceAdvice.pdf')
        ->and($attachment->isVisibleTo($studio))->toBeTrue();

    Storage::disk('local')->assertExists($attachment->path);
});

test('the remittance stays with the studio rather than going to the client', function () {
    Storage::fake('local');

    $team = Team::factory()->create();
    $record = agencyRecord($team);

    $attachment = app(StoreAttachment::class)->handle(
        attachable: $record,
        file: UploadedFile::fake()->create('RemittanceAdvice.pdf', 12, 'application/pdf'),
        sharedWithClient: false,
    );

    $this->actingAs(memberOf($team, ClientAccess::Full))
        ->get(route('attachments.download', $attachment))
        ->assertNotFound();

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('attachments.download', $attachment))
        ->assertOk();
});

test('a record does not draw down a fixed project price', function () {
    $team = Team::factory()->create();
    $project = $team->projects()->create([
        'reference' => 'PRJ-001',
        'title' => 'Website rebuild',
        'agreed_value' => 6600,
    ]);

    agencyRecord($team);

    expect($project->fresh()->contractInvoiced())->toBe(0)
        ->and($project->fresh()->balanceToInvoice())->toBe(6600);
});

test('the admin invoice list marks a record as one rather than as money owed', function () {
    $record = agencyRecord();

    Livewire\Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.invoices')
        ->assertSee('REC-0001')
        ->assertSee('record')
        ->assertSee('AGY9900112233445566');
});
