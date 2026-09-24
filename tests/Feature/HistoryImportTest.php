<?php

use App\Actions\Import\ImportHistory;
use App\Enums\ClientAccess;
use App\Enums\Currency;
use App\Enums\InvoiceStatus;
use App\Exceptions\ImportException;
use App\Models\Invoice;
use App\Models\RecurringInvoice;
use App\Models\StudioSetting;
use App\Models\Team;
use Illuminate\Support\Facades\Notification;

/**
 * The master CSV, in the one shape the importer knows.
 */
function masterFile(): string
{
    return base_path('tests/Fixtures/master-import.csv');
}

/**
 * Import the fixture.
 *
 * @return array<string, mixed>
 */
function importHistory(bool $dryRun = false): array
{
    return (new ImportHistory(StudioSetting::current()))->handle(masterFile(), $dryRun);
}

test('every invoice row arrives once, with its client opened', function () {
    importHistory();

    expect(Invoice::count())->toBe(7)
        ->and(Team::query()->where('is_personal', false)->count())->toBe(6);

    $this->assertDatabaseHas('invoices', ['number' => 'IN-0048', 'external_reference' => '0048']);
});

test('pence survive the import', function () {
    importHistory();

    expect(Invoice::query()->where('number', 'IN-0048')->value('amount'))->toBe(540.1);
});

test('a thousands separator is not read as a truncated amount', function () {
    importHistory();

    $invoice = Invoice::query()->where('number', 'IN-0000276')->sole();

    expect($invoice->amount)->toBe(5100.0)
        ->and($invoice->currency)->toBe(Currency::EUR);
});

test('a discount is kept rather than folded into the amount', function () {
    importHistory();

    $invoice = Invoice::query()->where('number', 'IN-0112')->sole();

    expect($invoice->amount)->toBe(6.0)
        ->and($invoice->discount)->toBe(19.0)
        ->and($invoice->subtotal())->toBe(25.0);
});

test('an invoice settled in part keeps what has come in', function () {
    importHistory();

    $invoice = Invoice::query()->where('number', 'IN-0113')->sole();

    expect($invoice->status)->toBe(InvoiceStatus::Partial)
        ->and($invoice->paid_to_date)->toBe(325.0)
        ->and($invoice->balance())->toBe(325.0);
});

test('an invoice with no due date falls back to the client payment terms', function () {
    importHistory();

    $invoice = Invoice::query()->where('number', 'IN-0094')->sole();

    expect($invoice->issued_on->toDateString())->toBe('2026-01-17')
        ->and($invoice->due_on->toDateString())->toBe('2026-02-07');
});

test('a payment date comes across, and only for what is actually paid', function () {
    $result = importHistory();

    expect(Invoice::query()->where('number', 'IN-0048')->value('paid_at')->toDateString())
        ->toBe('2025-04-10')
        // Still owed, so there is no date to record.
        ->and(Invoice::query()->where('number', 'IN-0113')->value('paid_at'))->toBeNull()
        ->and($result['dated'])->toBe(5)
        ->and($result['undated'])->toBeEmpty();
});

test('the contact details on a row land on the client', function () {
    importHistory();

    $team = Team::query()->where('name', 'Kintail Joinery & Sons')->sole();

    expect($team->billing_email)->toBe('accounts@kintail.test')
        ->and($team->address)->toBe("2 Mill Wynd\nBanchory\nAB31 5QA")
        ->and($team->company_number)->toBe('SC111111')
        ->and($team->currency)->toBe(Currency::GBP);
});

test('contact details the studio has already set are left alone', function () {
    Team::factory()->create([
        'name' => 'Kintail Joinery & Sons',
        'billing_email' => 'someone-else@kintail.test',
    ]);

    importHistory();

    expect(Team::query()->where('name', 'Kintail Joinery & Sons')->value('billing_email'))
        ->toBe('someone-else@kintail.test');
});

test('a client already on the books is matched rather than opened again', function () {
    $existing = Team::factory()->create(['name' => 'Kintail Joinery and Sons']);

    $result = importHistory();

    expect(Team::query()->where('name', 'like', 'Kintail%')->count())->toBe(1)
        ->and($result['clients'])->not->toContain('Kintail Joinery & Sons')
        ->and(Invoice::query()->where('number', 'IN-0048')->value('team_id'))->toBe($existing->id);
});

test('opening a client emails nobody', function () {
    Notification::fake();

    importHistory();

    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('team_invitations', 0);

    Notification::assertNothingSent();
});

test('a record row is written as a record, not an invoice to chase', function () {
    importHistory();

    $record = Invoice::query()->where('number', 'REC-0001')->sole();

    expect($record->record_only)->toBeTrue()
        ->and($record->status)->toBe(InvoiceStatus::Paid)
        ->and($record->external_reference)->toBe('AGY9900112233445566')
        ->and($record->reminderDue())->toBeNull()
        ->and($record->isOverdue())->toBeFalse();
});

test('a schedule row becomes a standing arrangement', function () {
    importHistory();

    $schedule = RecurringInvoice::query()
        ->whereHas('team', fn ($q) => $q->where('name', 'Tayside Opportunities'))
        ->sole();

    expect($schedule->amount)->toBe(6.0)
        ->and($schedule->discount)->toBe(19.0)
        ->and($schedule->day_of_month)->toBe(1)
        ->and($schedule->record_only)->toBeFalse()
        ->and($schedule->starts_on->toDateString())->toBe('2026-10-01');
});

test('a record schedule raises paid records rather than invoices', function () {
    importHistory();

    $schedule = RecurringInvoice::query()
        ->whereHas('team', fn ($q) => $q->where('name', 'Deveron Creative Partners'))
        ->sole();

    expect($schedule->record_only)->toBeTrue()
        ->and($schedule->day_of_month)->toBe(18);
});

test('history still owed is muted rather than chased from scratch', function () {
    importHistory();

    $owed = Invoice::query()->outstanding()->get();

    expect($owed)->toHaveCount(2)
        ->and($owed->pluck('reminders_paused_at'))->each->not->toBeNull();
});

test('a second run adds nothing and duplicates nothing', function () {
    importHistory();

    $result = importHistory();

    expect(Invoice::count())->toBe(7)
        ->and(RecurringInvoice::count())->toBe(2)
        ->and($result['invoices'])->toBeEmpty()
        ->and($result['skipped'])->toHaveCount(9);
});

test('a dry run writes nothing at all', function () {
    $result = importHistory(dryRun: true);

    expect($result['invoices'])->toHaveCount(7);

    $this->assertDatabaseCount('invoices', 0);
    $this->assertDatabaseCount('teams', 0);
    $this->assertDatabaseCount('recurring_invoices', 0);
});

test('imported history does not move the studio own numbering along', function () {
    importHistory();

    expect(Invoice::nextNumber())->toBe('RSC-0001');
});

test('records are kept out of the client portal', function () {
    importHistory();

    $team = Team::query()->where('name', 'Deveron Creative Partners')->sole();

    expect($team->invoices()->issued()->count())->toBe(0)
        ->and($team->invoices()->count())->toBe(1);
});

describe('a file that is not the master format', function () {
    test('is refused, naming what is missing', function () {
        expect(fn () => (new ImportHistory(StudioSetting::current()))
            ->handle(base_path('tests/Fixtures/not-the-master-file.csv')))
            ->toThrow(ImportException::class, 'does not look like the master file');
    });

    test('writes nothing', function () {
        try {
            (new ImportHistory(StudioSetting::current()))
                ->handle(base_path('tests/Fixtures/not-the-master-file.csv'));
        } catch (ImportException) {
            // The point of the test is what it left behind.
        }

        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('teams', 0);
    });
});

test('a row_type the importer does not know is refused by row number', function () {
    $path = sys_get_temp_dir().'/bad-row-type.csv';
    file_put_contents($path, implode("\n", [
        'row_type,client,amount,number,issued_on',
        'something,Corrie Studio,20.00,IN-0001,2026-01-01',
    ])."\n");

    expect(fn () => (new ImportHistory(StudioSetting::current()))->handle($path))
        ->toThrow(ImportException::class, 'Row 2 has a row_type of "something"');
});

test('a row with no invoice number is refused rather than guessed at', function () {
    $path = sys_get_temp_dir().'/no-number.csv';
    file_put_contents($path, implode("\n", [
        'row_type,client,amount,number,issued_on',
        'invoice,Corrie Studio,20.00,,2026-01-01',
    ])."\n");

    expect(fn () => (new ImportHistory(StudioSetting::current()))->handle($path))
        ->toThrow(ImportException::class, 'Row 2 has no number');
});

test('a row with something other than a date in it says which row', function () {
    $path = sys_get_temp_dir().'/bad-date.csv';
    file_put_contents($path, implode("\n", [
        'row_type,client,amount,number,issued_on',
        'invoice,Corrie Studio,20.00,IN-0001,not a date',
    ])."\n");

    expect(fn () => (new ImportHistory(StudioSetting::current()))->handle($path))
        ->toThrow(ImportException::class, 'Row 2');
});

test('the command reconciles the file and reports what it did', function () {
    $this->artisan('history:import', ['path' => masterFile()])
        // £1,463.60 of invoices plus the £525 agency record.
        ->expectsOutputToContain('£1,988.60')
        ->expectsOutputToContain('€5,100.00')
        ->expectsOutputToContain('Imported 7 invoices.')
        ->assertSuccessful();

    expect(Invoice::count())->toBe(7);
});

test('the command writes nothing on a dry run', function () {
    $this->artisan('history:import', ['path' => masterFile(), '--dry-run' => true])
        ->expectsOutputToContain('Nothing was written')
        ->assertSuccessful();

    $this->assertDatabaseCount('invoices', 0);
});

test('an imported client can be invited and then sees their history', function () {
    importHistory();

    $team = Team::query()->where('name', 'Kintail Joinery & Sons')->sole();

    // The whole point of the migration: the history is theirs to look at.
    $this->actingAs(memberOf($team, ClientAccess::Full))
        ->get(route('client.invoices'))
        ->assertOk()
        ->assertSee('IN-0048');
});
