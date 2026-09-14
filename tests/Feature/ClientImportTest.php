<?php

use App\Actions\Clients\ImportClients;
use App\Exceptions\ImportException;
use App\Models\Team;

/**
 * The Clients export, as Invoice Ninja writes it.
 */
function clientsExport(): string
{
    return base_path('tests/Fixtures/invoice-ninja-clients.csv');
}

/**
 * Import the fixture against the clients already on the books.
 *
 * @return array{filled: list<array{client: string, email: string|null}>, kept: list<string>, unmatched: list<string>}
 */
function importClients(bool $dryRun = false): array
{
    return (new ImportClients)->handle(clientsExport(), $dryRun);
}

test('a client with nothing on file gets their contact details', function () {
    $team = Team::factory()->create(['name' => 'Kintail Joinery & Sons']);

    importClients();

    expect($team->fresh()->billing_email)->toBe('accounts@kintail.test')
        ->and($team->fresh()->address)->toBe("2 Mill Wynd\nUnit 4\nBanchory\nAB31 5QA")
        ->and($team->fresh()->company_number)->toBe('SC111111');
});

test('an address the studio has already set is left alone', function () {
    $team = Team::factory()->create([
        'name' => 'Kintail Joinery & Sons',
        'billing_email' => 'someone-else@kintail.test',
    ]);

    $result = importClients();

    // The export is months old by the time it is used; whatever the studio has
    // corrected by hand since wins.
    expect($team->fresh()->billing_email)->toBe('someone-else@kintail.test')
        ->and($team->fresh()->address)->not->toBeNull()
        // Only this one business is on the books, so only this one row matches.
        ->and($result['filled'])->toHaveCount(1);
});

test('a client whose every field is already filled is reported as kept', function () {
    Team::factory()->create([
        'name' => 'Tayside Opportunities',
        'billing_email' => 'accounts@tayside.test',
        'address' => 'Somewhere',
    ]);

    $result = importClients();

    expect($result['kept'])->toContain('Tayside Opportunities');
});

test('a business spelled differently is still the same client', function () {
    $team = Team::factory()->create(['name' => 'Kintail Joinery and Sons']);

    importClients();

    expect(Team::query()->where('name', 'like', 'Kintail%')->count())->toBe(1)
        ->and($team->fresh()->billing_email)->toBe('accounts@kintail.test');
});

test('a client in the export that the studio has never invoiced is not opened', function () {
    Team::factory()->create(['name' => 'Corrie Studio']);

    $result = importClients();

    // The invoice import is the one place a client arrives from a file. A name
    // with no history behind it is reported, not created.
    expect(Team::query()->where('name', 'A Client We Never Invoiced')->exists())->toBeFalse()
        ->and($result['unmatched'])->toContain('A Client We Never Invoiced');
});

test('a contact with no email address is left without one', function () {
    $team = Team::factory()->create(['name' => 'Lochside Design']);

    importClients();

    // The row has a name and a city but no address to write to, and an
    // invitation sent to a blank one bounces silently.
    expect($team->fresh()->billing_email)->toBeNull()
        ->and($team->fresh()->address)->toBe("Inverness\nIV1 1AA");
});

test('a malformed email address is refused rather than stored', function () {
    $path = sys_get_temp_dir().'/clients-bad-email.csv';
    file_put_contents($path, implode("\n", [
        '"Client Name","Client Contact Email"',
        '"Corrie Studio","not an address"',
    ])."\n");

    $team = Team::factory()->create(['name' => 'Corrie Studio']);

    (new ImportClients)->handle($path);

    expect($team->fresh()->billing_email)->toBeNull();
});

test('a VAT number comes across when the export carries one', function () {
    $team = Team::factory()->create(['name' => 'Nordwind GmbH & Co KG']);

    importClients();

    expect($team->fresh()->vat_number)->toBe('DE123456789');
});

test('a dry run writes nothing', function () {
    $team = Team::factory()->create(['name' => 'Kintail Joinery & Sons']);

    $result = importClients(dryRun: true);

    expect($result['filled'])->not->toBeEmpty()
        ->and($team->fresh()->billing_email)->toBeNull();
});

test('the invoice report handed over as the clients export is refused', function () {
    try {
        (new ImportClients)->handle(base_path('tests/Fixtures/invoice-ninja-payments.csv'));
    } catch (ImportException $e) {
        expect($e->getMessage())->toContain('does not look like the Clients report')
            ->and($e->field())->toBe('clients');

        return;
    }

    $this->fail('The payments report was accepted as the clients export.');
});

test('nothing is written when the wrong report is handed over', function () {
    Team::factory()->create(['name' => 'Kintail Joinery & Sons']);

    try {
        (new ImportClients)->handle(base_path('tests/Fixtures/invoice-ninja-payments.csv'));
    } catch (ImportException) {
        // The point of the test is what it left behind.
    }

    expect(Team::query()->whereNotNull('billing_email')->count())->toBe(0);
});
