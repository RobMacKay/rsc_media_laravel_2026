<?php

use App\Enums\ClientAccess;
use App\Enums\InvoiceType;
use App\Enums\ProjectPhase;
use App\Enums\ProposalStatus;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\StudioSetting;
use App\Models\Team;
use App\Models\User;
use Livewire\Livewire;

test('a client can propose a project', function () {
    $team = Team::factory()->create();
    $user = memberOf($team, ClientAccess::Tickets);

    Livewire::actingAs($user)
        ->test('pages::client.projects')
        ->set('title', 'Van fleet booking board')
        ->set('brief', 'A shared board so the yard can see which vans are out.')
        ->set('goal', 'Stops two drivers taking the same van.')
        ->set('budget', '£1k–£3k')
        ->set('neededBy', 'before the October rush')
        ->call('propose')
        ->assertHasNoErrors();

    $proposal = Proposal::sole();

    expect($proposal->title)->toBe('Van fleet booking board')
        ->and($proposal->team_id)->toBe($team->id)
        ->and($proposal->requested_by)->toBe($user->id)
        ->and($proposal->status)->toBe(ProposalStatus::Submitted)
        ->and($proposal->reference)->toStartWith('PRJ-');
});

test('the budget chips select a bracket', function () {
    $user = memberOf(Team::factory()->create(), ClientAccess::Tickets);

    $component = Livewire::actingAs($user)
        ->test('pages::client.projects')
        ->set('proposeOpen', true)
        ->assertSet('budget', 'No idea yet')
        ->call('chooseBudget', 2)
        ->assertSet('budget', '£3k–£7k');

    // The chips must carry a callable expression, not an uncompiled directive.
    $component->assertSee('wire:click="chooseBudget(2)"', escape: false)
        ->assertDontSee('@js(');
});

test('an out of range budget chip leaves the choice alone', function () {
    Livewire::actingAs(memberOf(Team::factory()->create(), ClientAccess::Tickets))
        ->test('pages::client.projects')
        ->call('chooseBudget', 99)
        ->assertSet('budget', 'No idea yet');
});

test('a proposal needs a title, a brief and a budget we offer', function () {
    $user = memberOf(Team::factory()->create(), ClientAccess::Tickets);

    Livewire::actingAs($user)
        ->test('pages::client.projects')
        ->set('title', '')
        ->set('brief', '')
        ->set('budget', 'A million pounds')
        ->call('propose')
        ->assertHasErrors(['title', 'brief', 'budget']);

    expect(Proposal::count())->toBe(0);
});

test('a view-only person cannot propose', function () {
    Livewire::actingAs(memberOf(Team::factory()->create(), ClientAccess::View))
        ->test('pages::client.projects')
        ->set('title', 'Something')
        ->set('brief', 'Something else')
        ->call('propose')
        ->assertForbidden();

    expect(Proposal::count())->toBe(0);
});

test('references do not collide with existing projects', function () {
    $team = Team::factory()->create();
    Project::factory()->for($team)->create(['reference' => 'PRJ-004']);
    Proposal::factory()->for($team)->create(['reference' => 'PRJ-006']);

    Livewire::actingAs(memberOf($team, ClientAccess::Full))
        ->test('pages::client.projects')
        ->set('title', 'Next one')
        ->set('brief', 'Details.')
        ->call('propose')
        ->assertHasNoErrors();

    expect(Proposal::where('reference', 'PRJ-007')->exists())->toBeTrue();
});

test('signing off opens the project and raises the deposit invoice', function () {
    StudioSetting::current()->update(['vat_registered' => true, 'vat_rate' => 20, 'payment_terms_days' => 21]);

    $team = Team::factory()->create(['payment_terms_days' => 14]);
    $proposal = Proposal::factory()->for($team)->sent()->create([
        'reference' => 'PRJ-010',
        'title' => 'Trade counter stock pages',
        'price' => 3400,
        'deposit_percent' => 40,
        'weeks' => 5,
        'phases' => "Scoping | 18 Aug | Field list agreed.\nBuild | 1 Sep | The work.",
    ]);

    Livewire::actingAs(memberOf($team, ClientAccess::Full))
        ->test('pages::client.projects')
        ->call('approve', $proposal->id);

    $proposal->refresh();
    $project = Project::sole();
    $invoice = Invoice::sole();

    expect($proposal->status)->toBe(ProposalStatus::Approved)
        ->and($proposal->responded_at)->not->toBeNull()
        ->and($proposal->project_id)->toBe($project->id)
        ->and($project->reference)->toBe('PRJ-010')
        ->and($project->title)->toBe('Trade counter stock pages')
        ->and($project->phase)->toBe(ProjectPhase::Scoping)
        ->and($project->milestone)->toBe('Scoping')
        ->and($invoice->type)->toBe(InvoiceType::Deposit)
        ->and($invoice->amount)->toBe(1360)
        ->and($invoice->project_id)->toBe($project->id)
        ->and($invoice->due_on->toDateString())->toBe(now()->addDays(14)->toDateString());
});

test('only someone with billing access can sign a proposal off', function () {
    $team = Team::factory()->create();
    $proposal = Proposal::factory()->for($team)->sent()->create();

    Livewire::actingAs(memberOf($team, ClientAccess::Tickets))
        ->test('pages::client.projects')
        ->call('approve', $proposal->id)
        ->assertForbidden();

    expect($proposal->fresh()->status)->toBe(ProposalStatus::Sent)
        ->and(Project::count())->toBe(0)
        ->and(Invoice::count())->toBe(0);
});

test('a proposal that has not been sent cannot be signed off', function () {
    $team = Team::factory()->create();
    $proposal = Proposal::factory()->for($team)->create(['status' => ProposalStatus::Submitted]);

    Livewire::actingAs(memberOf($team, ClientAccess::Full))
        ->test('pages::client.projects')
        ->call('approve', $proposal->id)
        ->assertStatus(404);

    expect(Project::count())->toBe(0);
});

test('a client cannot sign off another business\'s proposal', function () {
    $proposal = Proposal::factory()->for(Team::factory()->create())->sent()->create();

    Livewire::actingAs(memberOf(Team::factory()->create(), ClientAccess::Full))
        ->test('pages::client.projects')
        ->call('approve', $proposal->id)
        ->assertStatus(404);

    expect($proposal->fresh()->status)->toBe(ProposalStatus::Sent);
});

test('the studio writes a proposal up and sends it', function () {
    $proposal = Proposal::factory()->create([
        'reference' => 'PRJ-011',
        'status' => ProposalStatus::Submitted,
    ]);

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.proposals')
        ->call('select', 'PRJ-011')
        ->set('scope', "Stock list page\nTrade pricing")
        ->set('phases', 'Scoping | 18 Aug | Field list agreed.')
        ->set('excluded', 'Photography.')
        ->set('price', 3400)
        ->set('depositPercent', 40)
        ->set('weeks', 5)
        ->call('send')
        ->assertHasNoErrors();

    $proposal->refresh();

    expect($proposal->status)->toBe(ProposalStatus::Sent)
        ->and($proposal->sent_at)->not->toBeNull()
        ->and($proposal->price)->toBe(3400)
        ->and($proposal->scopeLines())->toBe(['Stock list page', 'Trade pricing'])
        ->and($proposal->phaseRows())->toBe([
            ['name' => 'Scoping', 'date' => '18 Aug', 'note' => 'Field list agreed.'],
        ]);
});

test('a proposal cannot go out without scope and a price', function () {
    Proposal::factory()->create(['reference' => 'PRJ-012', 'status' => ProposalStatus::Submitted]);

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.proposals')
        ->call('select', 'PRJ-012')
        ->set('scope', '')
        ->set('price', 0)
        ->call('send')
        ->assertHasErrors(['scope', 'price']);

    expect(Proposal::sole()->status)->toBe(ProposalStatus::Submitted);
});

test('the studio can save a draft without sending it', function () {
    $proposal = Proposal::factory()->create(['reference' => 'PRJ-013', 'status' => ProposalStatus::Submitted]);

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.proposals')
        ->call('select', 'PRJ-013')
        ->set('scope', 'Half written')
        ->set('price', 500)
        ->call('saveDraft')
        ->assertHasNoErrors();

    $proposal->refresh();

    expect($proposal->scope)->toBe('Half written')
        ->and($proposal->status)->toBe(ProposalStatus::Submitted)
        ->and($proposal->sent_at)->toBeNull();
});

test('the admin proposal list is closed to clients', function () {
    $this->actingAs(memberOf(Team::factory()->create(), ClientAccess::Full))
        ->get(route('admin.proposals'))
        ->assertForbidden();

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.proposals'))
        ->assertOk();
});

test('a client only sees their own proposals', function () {
    $team = Team::factory()->create();
    Proposal::factory()->for($team)->create(['title' => 'Our own idea']);
    Proposal::factory()->for(Team::factory()->create())->create(['title' => 'Somebody elses idea']);

    $this->actingAs(memberOf($team, ClientAccess::Full))
        ->get(route('client.projects'))
        ->assertOk()
        ->assertSee('Our own idea')
        ->assertDontSee('Somebody elses idea');
});

test('phase rows survive a malformed line', function () {
    $proposal = Proposal::factory()->create([
        'phases' => "Scoping | 18 Aug | Agreed.\n\n   \nBuild",
    ]);

    expect($proposal->phaseRows())->toBe([
        ['name' => 'Scoping', 'date' => '18 Aug', 'note' => 'Agreed.'],
        ['name' => 'Build', 'date' => '', 'note' => ''],
    ]);
});

test('sending keeps the panel on the proposal that was sent', function () {
    $glencoe = Proposal::factory()->create([
        'reference' => 'PRJ-020',
        'title' => 'Gift voucher checkout',
        'status' => ProposalStatus::Submitted,
        'created_at' => now(),
    ]);

    $braemar = Proposal::factory()->create([
        'reference' => 'PRJ-021',
        'title' => 'Van fleet booking board',
        'status' => ProposalStatus::Submitted,
        'created_at' => now()->subDay(),
    ]);

    $component = Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.proposals');

    // No row clicked, so the panel falls back to whatever sorts first.
    expect($component->instance()->current->reference)->toBe('PRJ-020');

    $component->set('scope', 'Voucher page')->set('price', 2200)->call('send')->assertHasNoErrors();

    // Sending re-sorts the list; the panel must not drift onto the other one.
    expect($component->instance()->current->reference)->toBe('PRJ-020');

    $component->set('weeks', 6)->call('saveDraft')->assertHasNoErrors();

    expect($glencoe->fresh()->price)->toBe(2200)
        ->and($glencoe->fresh()->weeks)->toBe(6)
        ->and($braemar->fresh()->price)->toBe(0)
        ->and($braemar->fresh()->scope)->toBeNull();
});

test('the sign-off card quotes the terms the invoice will actually use', function () {
    StudioSetting::current()->update(['payment_terms_days' => 30]);

    $team = Team::factory()->create(['payment_terms_days' => null]);
    Proposal::factory()->for($team)->sent()->create(['title' => 'Trade counter stock pages']);

    $this->actingAs(memberOf($team, ClientAccess::Full))
        ->get(route('client.projects'))
        ->assertOk()
        ->assertSee('30 days from invoice')
        ->assertDontSee('21 days from invoice');
});

test('a proposal cannot be signed off twice', function () {
    $team = Team::factory()->create();
    $proposal = Proposal::factory()->for($team)->sent()->create(['reference' => 'PRJ-030']);

    $proposal->approve(StudioSetting::current());

    expect(fn () => $proposal->fresh()->approve(StudioSetting::current()))
        ->toThrow(RuntimeException::class);

    expect(Project::count())->toBe(1)
        ->and(Invoice::count())->toBe(1);
});

test('a long brief survives sign-off', function () {
    // proposals.brief is text and the form allows 5,000 characters, but
    // approve() copies it into projects.summary. When that was a varchar(255),
    // MySQL rejected the insert and rolled the whole approval back.
    $team = Team::factory()->create();
    $user = memberOf($team, ClientAccess::Full);

    $brief = str_repeat('A long and detailed brief about the work we need doing. ', 40);

    $proposal = Proposal::factory()->for($team)->create([
        'status' => ProposalStatus::Sent,
        'brief' => $brief,
        'price' => 4000,
        'deposit_percent' => 40,
    ]);

    Livewire::actingAs($user)
        ->test('pages::client.projects')
        ->call('approve', $proposal->id)
        ->assertHasNoErrors();

    expect($proposal->fresh()->project->summary)->toBe($brief);
});
