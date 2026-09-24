<?php

use App\Models\Invoice;
use App\Models\RecurringInvoice;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * An upload of the given fixture, as it would arrive from the browser.
 */
function uploadedFile(string $fixture, string $name = 'import.csv'): UploadedFile
{
    return UploadedFile::fake()->createWithContent(
        $name,
        (string) file_get_contents(base_path("tests/Fixtures/{$fixture}")),
    );
}

/**
 * The import screen with the master file attached, ready to preview.
 */
function importScreen(string $fixture = 'master-import.csv'): Testable
{
    return Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.import')
        ->set('file', uploadedFile($fixture));
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
    $this->assertDatabaseCount('recurring_invoices', 0);

    // Every user gets a personal team, so what matters is that no client
    // business was opened by the preview.
    expect(Team::query()->where('is_personal', false)->count())->toBe(0);
});

test('a preview shows the totals to reconcile against and every client in the file', function () {
    importScreen()
        ->call('preview')
        ->assertSee('£1,988.60')
        ->assertSee('€5,100.00')
        ->assertSee('Kintail Joinery &amp; Sons', escape: false)
        ->assertSee('Nordwind GmbH &amp; Co KG', escape: false)
        ->assertSee('7 invoices to import');
});

test('a preview says that opening a client emails nobody', function () {
    importScreen()
        ->call('preview')
        ->assertSee('clients to open')
        ->assertSee('nobody will be emailed');
});

test('a preview counts the standing arrangements', function () {
    importScreen()
        ->call('preview')
        ->assertSee('standing arrangements');
});

test('confirming imports the file and emails nobody', function () {
    Notification::fake();

    importScreen()
        ->call('preview')
        ->call('confirm')
        ->assertHasNoErrors()
        ->assertSee('7 invoices imported');

    expect(Invoice::count())->toBe(7)
        ->and(RecurringInvoice::count())->toBe(2)
        ->and(Team::query()->where('is_personal', false)->count())->toBe(6);

    // Only the studio admin who ran the import: not one contact was created.
    $this->assertDatabaseCount('users', 1);
    $this->assertDatabaseCount('team_invitations', 0);

    Notification::assertNothingSent();
});

test('a CSV that is not the master file is refused, naming what is missing', function () {
    importScreen('not-the-master-file.csv')
        ->call('preview')
        ->assertHasErrors('file')
        ->assertSee('does not look like the master file');

    $this->assertDatabaseCount('invoices', 0);
});

test('an import cannot be run without a file', function () {
    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.import')
        ->call('preview')
        ->assertHasErrors(['file' => 'required']);

    $this->assertDatabaseCount('invoices', 0);
});

test('a file that is not a spreadsheet is refused', function () {
    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.import')
        ->set('file', UploadedFile::fake()->create('notes.pdf', 8, 'application/pdf'))
        ->call('preview')
        ->assertHasErrors(['file' => 'mimes']);

    $this->assertDatabaseCount('invoices', 0);
});

test('the uploaded file is deleted once the import has run', function () {
    $screen = importScreen()->call('preview');

    $path = $screen->instance()->file->getRealPath();

    expect($path)->toBeReadableFile();

    $screen->call('confirm')->assertHasNoErrors();

    // A file holding every client and amount the studio has ever billed does
    // not get left on the server once the invoices table holds it — and
    // neither does the sidecar Livewire writes beside it.
    expect(file_exists($path))->toBeFalse()
        ->and(file_exists($path.'.json'))->toBeFalse()
        ->and($screen->instance()->file)->toBeNull();
});

test('cancelling deletes the uploaded file and keeps the database untouched', function () {
    $screen = importScreen()->call('preview');

    $path = $screen->instance()->file->getRealPath();

    $screen->call('cancel')
        ->assertSet('previewed', null)
        ->assertSee('Master CSV');

    expect(file_exists($path))->toBeFalse();

    $this->assertDatabaseCount('invoices', 0);
});

test('a second import of the same file adds nothing and says so', function () {
    importScreen()->call('preview')->call('confirm');

    importScreen()
        ->call('preview')
        ->assertSee('already here')
        ->assertSee('will be left alone');

    expect(Invoice::count())->toBe(7);
});

test('a note from the file is rendered as text rather than as markup', function () {
    importScreen()->call('preview')->assertDontSee('<a href', escape: false);
});

test('the invoices screen links to the importer, since it is not in the nav', function () {
    // A one-off migration does not hold a nav slot, so this link is the only
    // way anyone finds it. If it goes, the screen is unreachable by accident.
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.invoices'))
        ->assertOk()
        ->assertSee('import history')
        ->assertSee(route('admin.import'));
});
