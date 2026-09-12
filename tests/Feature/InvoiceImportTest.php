<?php

use App\Actions\Billing\ChaseInvoices;
use App\Actions\Billing\ImportInvoices;
use App\Enums\Currency;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Exceptions\ImportException;
use App\Models\Invoice;
use App\Models\StudioSetting;
use App\Models\Team;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * The invoice export, as Invoice Ninja writes it.
 */
function invoicesExport(): string
{
    return base_path('tests/Fixtures/invoice-ninja-invoices.csv');
}

/**
 * The separate payments export, which is the only place the dates live.
 */
function paymentsExport(): string
{
    return base_path('tests/Fixtures/invoice-ninja-payments.csv');
}

/**
 * Import the fixture export.
 *
 * @return array{invoices: Collection<int, Invoice>, skipped: list<string>, teams: list<string>, dated: int, undated: list<string>, totals: array<string, float>}
 */
function importExport(?string $payments = null, bool $dryRun = false): array
{
    return (new ImportInvoices(StudioSetting::current()))
        ->handle(invoicesExport(), $payments, $dryRun);
}

test('every invoice in the export arrives once', function () {
    importExport();

    expect(Invoice::count())->toBe(6);

    $this->assertDatabaseHas('invoices', [
        'number' => 'IN-0048',
        'external_reference' => '0048',
        'type' => InvoiceType::AdHoc->value,
    ]);
});

test('pence survive the import', function () {
    importExport();

    expect(Invoice::query()->where('number', 'IN-0048')->value('amount'))->toBe(540.1);
});

test('a thousands separator is not read as a truncated amount', function () {
    importExport();

    $invoice = Invoice::query()->where('number', 'IN-0000276')->sole();

    expect($invoice->amount)->toBe(5100.0)
        ->and($invoice->currency)->toBe(Currency::EUR);
});

test('a discount is kept rather than folded into the amount', function () {
    importExport();

    $invoice = Invoice::query()->where('number', 'IN-0112')->sole();

    expect($invoice->amount)->toBe(6.0)
        ->and($invoice->discount)->toBe(19.0)
        ->and($invoice->subtotal())->toBe(25.0);
});

test('an invoice settled in part keeps what has come in', function () {
    importExport();

    $invoice = Invoice::query()->where('number', 'IN-0113')->sole();

    expect($invoice->status)->toBe(InvoiceStatus::Partial)
        ->and($invoice->paid_to_date)->toBe(325.0)
        ->and($invoice->balance())->toBe(325.0)
        ->and($invoice->status->isOutstanding())->toBeTrue();
});

test('an invoice with no due date falls back to the client payment terms', function () {
    importExport();

    // Issued 26 December 2022, with nothing in the due date column, so it
    // falls due the studio's default number of days later.
    $invoice = Invoice::query()->where('number', 'IN-0000276')->sole();
    $terms = $invoice->team->effectivePaymentTerms(StudioSetting::current());

    expect($invoice->issued_on->toDateString())->toBe('2022-12-26')
        ->and($invoice->due_on->toDateString())->toBe('2023-01-16')
        ->and($terms)->toBe(21);
});

test('a rich text note comes across as readable text with its link intact', function () {
    importExport();

    $note = Invoice::query()->where('number', 'IN-0094')->value('note');

    expect($note)->toBe("Hosting.\n\nhttps://example.test/folder");
});

test('the bank details footer is dropped rather than copied onto every invoice', function () {
    importExport();

    expect(Invoice::query()->whereNotNull('note')->pluck('note'))
        ->each->not->toContain('Bank Details');
});

test('an imported invoice charges no VAT', function () {
    StudioSetting::current()->update(['vat_registered' => true, 'vat_number' => 'GB123456789']);

    importExport();

    expect(Invoice::query()->pluck('vat_rate')->unique()->all())->toBe([0.0]);
});

test('the purchase order on the invoice is the one the client quoted for that job', function () {
    importExport();

    $team = Team::query()->where('name', 'Kintail Joinery & Sons')->sole();
    $team->update(['purchase_order_ref' => 'STANDING-1']);

    $invoice = Invoice::query()->where('number', 'IN-0048')->sole();

    expect($invoice->po_number)->toBe('6.130B')
        ->and($invoice->purchaseOrderRef())->toBe('6.130B');
});

test('a client with no invoice of its own falls back to its standing purchase order', function () {
    importExport();

    $team = Team::query()->where('name', 'Corrie Studio')->sole();
    $team->update(['purchase_order_ref' => 'STANDING-2']);

    $invoice = Invoice::query()->where('number', 'IN-0094')->sole();

    expect($invoice->po_number)->toBeNull()
        ->and($invoice->purchaseOrderRef())->toBe('STANDING-2');
});

test('a client in the export is opened as a business with nobody emailed', function () {
    Notification::fake();

    $result = importExport();

    expect($result['teams'])->toHaveCount(5)
        ->and(Team::query()->where('name', 'Kintail Joinery & Sons')->exists())->toBeTrue()
        ->and(Team::query()->where('name', 'Kintail Joinery & Sons')->sole()->members()->count())->toBe(0);

    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('team_invitations', 0);

    Notification::assertNothingSent();
});

test('a client billed in euros is opened in euros', function () {
    importExport();

    expect(Team::query()->where('name', 'Nordwind GmbH & Co KG')->sole()->currency)->toBe(Currency::EUR);
});

test('an existing client is matched rather than opened a second time', function () {
    // The studio already has this one on the books, spelled out in full.
    $existing = Team::factory()->create(['name' => 'Kintail Joinery and Sons']);

    $result = importExport();

    expect(Team::query()->where('name', 'like', 'Kintail%')->count())->toBe(1)
        ->and($result['teams'])->not->toContain('Kintail Joinery & Sons')
        ->and(Invoice::query()->where('number', 'IN-0048')->value('team_id'))->toBe($existing->id);
});

test('a second run adds nothing and duplicates nothing', function () {
    importExport();

    $result = importExport();

    expect(Invoice::count())->toBe(6)
        ->and($result['invoices'])->toBeEmpty()
        ->and($result['skipped'])->toHaveCount(6);
});

test('a dry run writes nothing at all', function () {
    $result = importExport(dryRun: true);

    expect($result['invoices'])->toHaveCount(6);

    $this->assertDatabaseCount('invoices', 0);
    $this->assertDatabaseCount('teams', 0);
});

test('an imported invoice still owed is muted rather than chased from scratch', function () {
    Notification::fake();

    importExport();

    $outstanding = Invoice::query()->outstanding()->get();

    expect($outstanding)->toHaveCount(2)
        ->and($outstanding->pluck('reminders_paused_at'))->each->not->toBeNull()
        ->and($outstanding->every(fn (Invoice $invoice) => $invoice->reminderDue() === null))->toBeTrue();

    app(ChaseInvoices::class)->sendReminders();

    Notification::assertNothingSent();
});

test('a settled imported invoice is not muted, since there is nothing to mute', function () {
    importExport();

    expect(Invoice::query()->where('status', InvoiceStatus::Paid)->pluck('reminders_paused_at'))
        ->each->toBeNull();
});

test('a paid invoice is left without a date when no payments export is given', function () {
    $result = importExport();

    expect($result['dated'])->toBe(0)
        ->and($result['undated'])->toHaveCount(4)
        ->and(Invoice::query()->whereNotNull('paid_at')->count())->toBe(0);
});

test('payment dates are filled in from the payments export', function () {
    $result = importExport(payments: paymentsExport());

    expect($result['dated'])->toBe(4)
        ->and($result['undated'])->toBeEmpty()
        ->and(Invoice::query()->where('number', 'IN-0048')->value('paid_at')->toDateString())
        ->toBe('2025-04-10');
});

test('an invoice settled in instalments is dated by the last payment', function () {
    // 0048 has two payment rows, the later one listed first in the export.
    importExport(payments: paymentsExport());

    expect(Invoice::query()->where('number', 'IN-0048')->value('paid_at')->toDateString())
        ->toBe('2025-04-10');
});

test('payment dates can be backfilled onto invoices already imported', function () {
    importExport();

    $result = importExport(payments: paymentsExport());

    expect($result['invoices'])->toBeEmpty()
        ->and($result['dated'])->toBe(4)
        ->and(Invoice::query()->where('number', 'IN-0000276')->value('paid_at')->toDateString())
        ->toBe('2023-01-09');
});

test('an unpaid invoice is not dated even when the payments export mentions it', function () {
    importExport(payments: paymentsExport());

    // 0113 is only part paid, so it has no settled date to record.
    expect(Invoice::query()->where('number', 'IN-0113')->value('paid_at'))->toBeNull();
});

describe('the file it is handed is not the report it needs', function () {
    test('the clients report is refused, naming what is missing', function () {
        expect(fn () => (new ImportInvoices(StudioSetting::current()))
            ->handle(base_path('tests/Fixtures/invoice-ninja-clients.csv')))
            ->toThrow(ImportException::class, 'does not look like the Invoice report');
    });

    test('the payments report in the invoices slot says which box it belongs in', function () {
        try {
            (new ImportInvoices(StudioSetting::current()))->handle(paymentsExport());
        } catch (ImportException $e) {
            expect($e->getMessage())->toContain('goes in the payments box')
                ->and($e->field())->toBe('invoices');

            return;
        }

        $this->fail('The payments report was accepted as the invoice export.');
    });

    test('an empty file is refused rather than read as no invoices', function () {
        expect(fn () => (new ImportInvoices(StudioSetting::current()))
            ->handle(base_path('tests/Fixtures/invoice-ninja-empty.csv')))
            ->toThrow(ImportException::class, 'That file is empty');
    });

    test('a missing file is refused against the upload it was for', function () {
        try {
            (new ImportInvoices(StudioSetting::current()))->handle('/no/such/export.csv');
        } catch (ImportException $e) {
            expect($e->field())->toBe('invoices');

            return;
        }

        $this->fail('A path that does not exist was accepted.');
    });

    test('nothing is written when the wrong report is handed over', function () {
        try {
            (new ImportInvoices(StudioSetting::current()))
                ->handle(base_path('tests/Fixtures/invoice-ninja-clients.csv'));
        } catch (ImportException) {
            // The point of the test is what it left behind.
        }

        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('teams', 0);
    });
});

test('a semicolon separated export written by a spreadsheet still imports', function () {
    // Excel rewrites the separator by locale and leaves a byte order mark on
    // the first column name, which would otherwise be unmatchable.
    (new ImportInvoices(StudioSetting::current()))
        ->handle(base_path('tests/Fixtures/invoice-ninja-invoices-semicolon-bom.csv'));

    expect(Invoice::count())->toBe(6)
        ->and(Invoice::query()->where('number', 'IN-0048')->value('amount'))->toBe(540.1);
});

test('a row with something other than a date in it says which row', function () {
    try {
        (new ImportInvoices(StudioSetting::current()))
            ->handle(base_path('tests/Fixtures/invoice-ninja-invoices-bad-date.csv'));
    } catch (ImportException $e) {
        expect($e->getMessage())->toContain('Row 2')
            ->and($e->getMessage())->toContain('not a date');

        $this->assertDatabaseCount('invoices', 0);

        return;
    }

    $this->fail('A row with no usable date was imported anyway.');
});

test('a row with no invoice number is skipped rather than guessed at', function () {
    (new ImportInvoices(StudioSetting::current()))
        ->handle(base_path('tests/Fixtures/invoice-ninja-invoices-missing-number.csv'));

    // The good row comes in; the one with nothing to identify it does not,
    // because a blank number cannot be told apart from any other blank.
    expect(Invoice::count())->toBe(1)
        ->and(Invoice::query()->value('number'))->toBe('IN-0048');
});

test('the invoice report handed over as the payments report is refused', function () {
    expect(fn () => importExport(payments: invoicesExport()))
        ->toThrow(RuntimeException::class, 'has no payment dates in it');
});

test('nothing is written when the payments file turns out to be the wrong report', function () {
    try {
        importExport(payments: invoicesExport());
    } catch (RuntimeException) {
        // The point of the test is what it left behind.
    }

    $this->assertDatabaseCount('invoices', 0);
});

test('imported history does not move the studio own numbering along', function () {
    importExport();

    expect(Invoice::nextNumber())->toBe('RSC-0001');
});

test('the command prints a per-currency total to reconcile against', function () {
    // Only the plain lines are asserted: Symfony renders the per-client table
    // straight to its own output, which the console test helper never sees.
    $this->artisan('invoices:import', [
        'path' => invoicesExport(),
        '--payments' => paymentsExport(),
    ])
        ->expectsOutputToContain('£1,463.60')
        ->expectsOutputToContain('€5,100.00')
        ->expectsOutputToContain('Kintail Joinery & Sons')
        ->expectsOutputToContain('Imported 6 invoices.')
        ->assertSuccessful();

    expect(Invoice::count())->toBe(6)
        ->and((float) Invoice::query()->where('currency', 'GBP')->sum('amount'))->toBe(1463.6)
        ->and((float) Invoice::query()->where('currency', 'EUR')->sum('amount'))->toBe(5100.0);
});

test('the command writes nothing on a dry run and says so', function () {
    $this->artisan('invoices:import', ['path' => invoicesExport(), '--dry-run' => true])
        ->expectsOutputToContain('Nothing was written')
        ->assertSuccessful();

    $this->assertDatabaseCount('invoices', 0);
});

test('the command fails rather than guessing when the payments report is wrong', function () {
    $this->artisan('invoices:import', [
        'path' => invoicesExport(),
        '--payments' => invoicesExport(),
    ])
        ->expectsOutputToContain('has to be "Payment", not "Invoice"')
        ->assertFailed();

    $this->assertDatabaseCount('invoices', 0);
});
