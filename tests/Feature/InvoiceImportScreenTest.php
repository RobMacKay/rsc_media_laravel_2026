<?php

use App\Models\Invoice;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * An upload of the given fixture, as it would arrive from the browser.
 */
function uploadedExport(string $fixture, string $name = 'report.csv'): UploadedFile
{
    return UploadedFile::fake()->createWithContent(
        $name,
        (string) file_get_contents(base_path("tests/Fixtures/{$fixture}")),
    );
}

/**
 * The import screen with the invoice export attached, ready to preview.
 */
function importScreen(?UploadedFile $payments = null): Testable
{
    $screen = Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.import')
        ->set('invoices', uploadedExport('invoice-ninja-invoices.csv', 'invoices.csv'));

    return $payments === null ? $screen : $screen->set('payments', $payments);
}

test('the import screen is closed to clients', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('admin.import'))
        ->assertForbidden();

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.import'))
        ->assertOk();
});

test('a preview writes nothing at all', function () {
    importScreen()->call('preview')->assertHasNoErrors();

    $this->assertDatabaseCount('invoices', 0);

    // Every user gets a personal team, so what matters is that no client
    // business was opened by the preview.
    expect(Team::query()->where('is_personal', false)->count())->toBe(0);
});

test('a preview shows the totals to reconcile against and every client in the export', function () {
    importScreen()
        ->call('preview')
        ->assertSee('£1,463.60')
        ->assertSee('€5,100.00')
        ->assertSee('Kintail Joinery &amp; Sons', escape: false)
        ->assertSee('Nordwind GmbH &amp; Co KG', escape: false)
        ->assertSee('6 invoices to import');
});

test('a preview says that opening a client emails nobody', function () {
    importScreen()
        ->call('preview')
        ->assertSee('clients to open')
        ->assertSee('nobody will be emailed');
});

test('a preview warns when paid invoices would have no date', function () {
    importScreen()
        ->call('preview')
        ->assertSee('paid invoices have no date');
});

test('confirming imports the export and emails nobody', function () {
    Notification::fake();

    importScreen()
        ->call('preview')
        ->call('confirm')
        ->assertHasNoErrors()
        ->assertSee('6 invoices imported');

    expect(Invoice::count())->toBe(6)
        ->and(Team::query()->where('is_personal', false)->count())->toBe(5);

    // Only the studio admin who ran the import: not one contact was created.
    $this->assertDatabaseCount('users', 1);
    $this->assertDatabaseCount('team_invitations', 0);

    Notification::assertNothingSent();
});

test('confirming with the payments export fills in the dates', function () {
    importScreen(uploadedExport('invoice-ninja-payments.csv', 'payments.csv'))
        ->call('preview')
        ->call('confirm')
        ->assertHasNoErrors();

    expect(Invoice::query()->whereNotNull('paid_at')->count())->toBe(4)
        ->and(Invoice::query()->where('number', 'IN-0048')->value('paid_at')->toDateString())
        ->toBe('2025-04-10');
});

test('the invoice report uploaded as the payments report is refused against that field', function () {
    importScreen(uploadedExport('invoice-ninja-invoices.csv', 'wrong.csv'))
        ->call('preview')
        ->assertHasErrors('payments');

    $this->assertDatabaseCount('invoices', 0);
});

test('the message for the wrong report says which report to export instead', function () {
    $errors = importScreen(uploadedExport('invoice-ninja-invoices.csv', 'wrong.csv'))
        ->call('preview')
        ->errors();

    expect($errors->first('payments'))->toContain('has to be "Payment", not "Invoice"');
});

test('an import cannot be run without the invoice export', function () {
    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.import')
        ->call('preview')
        ->assertHasErrors(['invoices' => 'required']);

    $this->assertDatabaseCount('invoices', 0);
});

test('a file that is not a spreadsheet is refused', function () {
    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.import')
        ->set('invoices', UploadedFile::fake()->create('notes.pdf', 8, 'application/pdf'))
        ->call('preview')
        ->assertHasErrors(['invoices' => 'mimes']);

    $this->assertDatabaseCount('invoices', 0);
});

test('the uploaded files are deleted once the import has run', function () {
    $screen = importScreen(uploadedExport('invoice-ninja-payments.csv', 'payments.csv'))
        ->call('preview');

    $paths = [
        $screen->instance()->invoices->getRealPath(),
        $screen->instance()->payments->getRealPath(),
    ];

    expect($paths)->each->toBeReadableFile();

    $screen->call('confirm')->assertHasNoErrors();

    // A CSV of every client and amount the studio has ever billed does not get
    // left on the server once the invoices table holds it — and neither does
    // the sidecar Livewire writes beside it, which names the original file.
    expect(file_exists($paths[0]))->toBeFalse()
        ->and(file_exists($paths[1]))->toBeFalse()
        ->and(file_exists($paths[0].'.json'))->toBeFalse()
        ->and(file_exists($paths[1].'.json'))->toBeFalse()
        ->and($screen->instance()->invoices)->toBeNull();
});

test('cancelling deletes the uploaded file and keeps the database untouched', function () {
    $screen = importScreen()->call('preview');

    $path = $screen->instance()->invoices->getRealPath();

    $screen->call('cancel')
        ->assertSet('previewed', null)
        ->assertSee('Invoice export');

    expect(file_exists($path))->toBeFalse();

    $this->assertDatabaseCount('invoices', 0);
});

test('a second import of the same export adds nothing and says so', function () {
    importScreen()->call('preview')->call('confirm');

    importScreen()
        ->call('preview')
        ->assertSee('already here')
        ->assertSee('will be left alone');

    expect(Invoice::count())->toBe(6);
});

test('an imported note is rendered as text rather than as markup', function () {
    // The notes arrive from Invoice Ninja as HTML and are stripped to text on the
    // way in. The preview must not be the one place that trusts them.
    importScreen()->call('preview')->assertDontSee('<a href', escape: false);
});
