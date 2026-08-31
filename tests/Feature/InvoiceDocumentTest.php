<?php

use App\Enums\ClientAccess;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\StudioSetting;
use App\Models\Team;

test('a client can view their own invoice', function () {
    $team = Team::factory()->create(['name' => 'Braemar Joinery', 'billing_email' => 'accounts@braemarjoinery.co.uk']);
    Invoice::factory()->for($team)->create([
        'number' => 'RSC-0143',
        'note' => 'Care & Support — August',
        'amount' => 180,
        'vat_rate' => 20,
    ]);

    $this->actingAs(memberOf($team, ClientAccess::Full))
        ->get(route('client.invoices.show', 'RSC-0143'))
        ->assertOk()
        ->assertSee('RSC-0143')
        ->assertSee('Braemar Joinery')
        ->assertSee('Care &amp; Support — August', escape: false)
        ->assertSee('£180.00')
        ->assertSee('£36.00')   // VAT at 20%
        ->assertSee('£216.00'); // total
});

test('the invoice shows the studio letterhead and bank details', function () {
    StudioSetting::current()->update([
        'company_name' => 'RSC Media Ltd',
        'company_number' => 'SC512347',
        'address' => "Unit 4, Bridgend Works\nDunkeld",
        'account_name' => 'RSC Media Ltd',
        'bank_name' => 'Starling Bank',
        'sort_code' => '60-83-71',
        'account_number' => '41028853',
        'vat_registered' => true,
        'vat_number' => 'GB412 8873 09',
    ]);

    $team = Team::factory()->create();
    Invoice::factory()->for($team)->create(['number' => 'RSC-0200']);

    $this->actingAs(memberOf($team, ClientAccess::Full))
        ->get(route('client.invoices.show', 'RSC-0200'))
        ->assertOk()
        ->assertSee('Unit 4, Bridgend Works')
        ->assertSee('Starling Bank')
        ->assertSee('60-83-71')
        ->assertSee('41028853')
        ->assertSee('SC512347')
        ->assertSee('GB412 8873 09');
});

test('the payment reference follows the format in settings', function () {
    StudioSetting::current()->update(['reference_format' => 'RSCM/{invoice}']);

    $team = Team::factory()->create();
    Invoice::factory()->for($team)->create(['number' => 'RSC-0143']);

    $this->actingAs(memberOf($team, ClientAccess::Full))
        ->get(route('client.invoices.show', 'RSC-0143'))
        ->assertOk()
        ->assertSee('RSCM/0143');
});

test('an invoice downloads as a PDF', function () {
    $team = Team::factory()->create();
    Invoice::factory()->for($team)->create(['number' => 'RSC-0143']);

    $response = $this->actingAs(memberOf($team, ClientAccess::Full))
        ->get(route('client.invoices.pdf', 'RSC-0143'));

    $response->assertOk()
        ->assertHeader('content-type', 'application/pdf')
        ->assertDownload('RSC-0143.pdf');

    expect($response->getContent())->toStartWith('%PDF');
});

test('a client cannot reach another business\'s invoice or PDF', function () {
    $other = Team::factory()->create();
    Invoice::factory()->for($other)->create(['number' => 'RSC-0999']);

    $intruder = memberOf(Team::factory()->create(), ClientAccess::Full);

    $this->actingAs($intruder)->get(route('client.invoices.show', 'RSC-0999'))->assertNotFound();
    $this->actingAs($intruder)->get(route('client.invoices.pdf', 'RSC-0999'))->assertNotFound();
});

test('people without billing access cannot see an invoice or its PDF', function () {
    $team = Team::factory()->create();
    Invoice::factory()->for($team)->create(['number' => 'RSC-0143']);

    $this->actingAs(memberOf($team, ClientAccess::Tickets))
        ->get(route('client.invoices.show', 'RSC-0143'))
        ->assertForbidden();

    $this->actingAs(memberOf($team, ClientAccess::Tickets))
        ->get(route('client.invoices.pdf', 'RSC-0143'))
        ->assertForbidden();
});

test('guests are sent to the login screen', function () {
    Invoice::factory()->create(['number' => 'RSC-0143']);

    $this->get(route('client.invoices.pdf', 'RSC-0143'))->assertRedirect(route('login'));
});

test('an unregistered studio shows no VAT on the invoice', function () {
    StudioSetting::current()->update(['vat_registered' => false]);

    $team = Team::factory()->create();
    Invoice::factory()->for($team)->create(['number' => 'RSC-0300', 'amount' => 500, 'vat_rate' => 0]);

    $this->actingAs(memberOf($team, ClientAccess::Full))
        ->get(route('client.invoices.show', 'RSC-0300'))
        ->assertOk()
        ->assertSee('No VAT is charged on this invoice.');
});

test('an overdue invoice is marked as such', function () {
    $team = Team::factory()->create();
    Invoice::factory()->for($team)->create([
        'number' => 'RSC-0400',
        'status' => InvoiceStatus::Sent,
        'due_on' => now()->subWeek(),
    ]);

    $this->actingAs(memberOf($team, ClientAccess::Full))
        ->get(route('client.invoices.show', 'RSC-0400'))
        ->assertOk()
        ->assertSee('overdue');
});

test('the invoice names the project it belongs to', function () {
    $team = Team::factory()->create();
    $project = Project::factory()->for($team)->create(['reference' => 'PRJ-004']);
    Invoice::factory()->for($team)->create(['number' => 'RSC-0500', 'project_id' => $project->id]);

    $this->actingAs(memberOf($team, ClientAccess::Full))
        ->get(route('client.invoices.show', 'RSC-0500'))
        ->assertOk()
        ->assertSee('PRJ-004');
});

test('the PDF is always light, whatever theme the client is using', function () {
    $team = Team::factory()->create();
    $invoice = Invoice::factory()->for($team)->create(['number' => 'RSC-0600']);

    $html = view('pdf.invoice', [
        'invoice' => $invoice->load(['team', 'project']),
        'settings' => StudioSetting::current(),
        'terms' => 21,
    ])->render();

    // The document carries its own colours rather than the app's theme tokens,
    // so it cannot come out dark just because the client was viewing it dark.
    expect($html)->not->toContain('var(--rsc-')
        ->and($html)->not->toContain('dark:')
        ->and($html)->toContain('color: #0a2029');
});
