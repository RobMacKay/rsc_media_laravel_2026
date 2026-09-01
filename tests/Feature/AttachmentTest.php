<?php

use App\Actions\Attachments\StoreAttachment;
use App\Enums\ClientAccess;
use App\Models\Attachment;
use App\Models\Project;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
});

test('a stored file lands on the private disk under a generated name', function () {
    $ticket = Ticket::factory()->create();
    $uploader = User::factory()->create();

    $attachment = app(StoreAttachment::class)->handle(
        $ticket,
        UploadedFile::fake()->image('screen shot.png', 10, 10),
        $uploader,
    );

    Storage::disk('local')->assertExists($attachment->path);

    expect($attachment->name)->toBe('screen shot.png')
        ->and($attachment->kind)->toBe('PNG')
        ->and($attachment->uploaded_by)->toBe($uploader->id)
        ->and($attachment->shared_with_client)->toBeTrue()
        // The name on disk is a ULID, so nothing user-supplied reaches it and
        // two people uploading the same filename cannot collide.
        ->and($attachment->path)->not->toContain('screen shot')
        ->and($attachment->path)->toStartWith('attachments/tickets/'.$ticket->id.'/');
});

test('two files with the same name do not overwrite each other', function () {
    $ticket = Ticket::factory()->create();

    $first = app(StoreAttachment::class)->handle($ticket, UploadedFile::fake()->create('report.pdf', 20));
    $second = app(StoreAttachment::class)->handle($ticket, UploadedFile::fake()->create('report.pdf', 30));

    expect($first->path)->not->toBe($second->path);

    Storage::disk('local')->assertExists($first->path);
    Storage::disk('local')->assertExists($second->path);
});

test('the kind badge falls back for names it cannot read', function () {
    expect(Attachment::kindFor('quote.pdf'))->toBe('PDF')
        ->and(Attachment::kindFor('notes.MD'))->toBe('MD')
        ->and(Attachment::kindFor('Makefile'))->toBe('FILE')
        ->and(Attachment::kindFor('archive.tar.gzipped'))->toBe('GZIPPED')
        // Anything too long for the kind column falls back rather than truncating.
        ->and(Attachment::kindFor('backup.tar.compressed'))->toBe('FILE');
});

test('deleting the record takes the file off disk with it', function () {
    $ticket = Ticket::factory()->create();

    $attachment = app(StoreAttachment::class)->handle($ticket, UploadedFile::fake()->create('spec.pdf', 10));
    $path = $attachment->path;

    $attachment->delete();

    Storage::disk('local')->assertMissing($path);
});

test('a client can attach a screenshot when raising a ticket', function () {
    $team = Team::factory()->create();
    $user = memberOf($team, ClientAccess::Full);

    Livewire::actingAs($user)
        ->test('pages::client.tickets')
        ->set('subject', 'Booking form drops the phone number')
        ->set('description', 'It is not coming through on the confirmation email.')
        ->set('upload', UploadedFile::fake()->image('screenshot.png'))
        ->call('save')
        ->assertHasNoErrors();

    $ticket = $team->tickets()->sole();

    expect($ticket->attachments)->toHaveCount(1)
        ->and($ticket->attachments->first()->name)->toBe('screenshot.png');
});

test('a client can attach a file to a ticket that is already open', function () {
    $team = Team::factory()->create();
    $user = memberOf($team, ClientAccess::Full);
    $ticket = Ticket::factory()->for($team)->create(['reference' => 'RSC-1200']);

    Livewire::actingAs($user)
        ->test('pages::client.tickets')
        ->call('openTicket', 'RSC-1200')
        ->set('reply', UploadedFile::fake()->image('after.png'))
        ->call('attachToTicket')
        ->assertHasNoErrors()
        ->assertSet('reply', null);

    expect($ticket->attachments()->count())->toBe(1);
});

test('a client cannot attach something we would not want to serve back', function () {
    $team = Team::factory()->create();
    $user = memberOf($team, ClientAccess::Full);
    $ticket = Ticket::factory()->for($team)->create(['reference' => 'RSC-1201']);

    Livewire::actingAs($user)
        ->test('pages::client.tickets')
        ->call('openTicket', 'RSC-1201')
        ->set('reply', UploadedFile::fake()->create('shell.php', 4))
        ->call('attachToTicket')
        ->assertHasErrors('reply');

    expect($ticket->attachments()->count())->toBe(0);
});

test('a client cannot attach a file over the limit', function () {
    $team = Team::factory()->create();
    $user = memberOf($team, ClientAccess::Full);
    $ticket = Ticket::factory()->for($team)->create(['reference' => 'RSC-1202']);

    Livewire::actingAs($user)
        ->test('pages::client.tickets')
        ->call('openTicket', 'RSC-1202')
        ->set('reply', UploadedFile::fake()->create('huge.pdf', Attachment::CLIENT_MAX_KB + 1))
        ->call('attachToTicket')
        ->assertHasErrors('reply');

    expect($ticket->attachments()->count())->toBe(0);
});

test('view-only people cannot attach anything', function () {
    $team = Team::factory()->create();
    $user = memberOf($team, ClientAccess::View);
    Ticket::factory()->for($team)->create(['reference' => 'RSC-1203']);

    Livewire::actingAs($user)
        ->test('pages::client.tickets')
        ->call('openTicket', 'RSC-1203')
        ->set('reply', UploadedFile::fake()->image('shot.png'))
        ->call('attachToTicket')
        ->assertForbidden();
});

test('the studio can attach several files at once and choose who sees them', function () {
    $ticket = Ticket::factory()->create(['reference' => 'RSC-1204']);

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.queue')
        ->call('select', 'RSC-1204')
        ->set('shareUploads', false)
        ->set('uploads', [
            UploadedFile::fake()->create('quote.pdf', 40),
            UploadedFile::fake()->create('notes.md', 2),
        ])
        ->call('attachFiles')
        ->assertHasNoErrors();

    expect($ticket->attachments()->count())->toBe(2)
        ->and($ticket->attachments()->pluck('shared_with_client')->unique()->all())->toBe([false]);
});

test('the studio can attach files to a job', function () {
    $project = Project::factory()->create();

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.jobs')
        ->call('toggleFiles', $project->id)
        ->set('uploads', [UploadedFile::fake()->create('scope.pdf', 30)])
        ->call('attachFiles')
        ->assertHasNoErrors();

    expect($project->attachments()->count())->toBe(1);
});

test('the studio can remove a file, and it goes off disk', function () {
    $ticket = Ticket::factory()->create(['reference' => 'RSC-1205']);
    $attachment = app(StoreAttachment::class)->handle($ticket, UploadedFile::fake()->create('wrong.pdf', 5));

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.queue')
        ->call('select', 'RSC-1205')
        ->call('removeFile', $attachment->id);

    Storage::disk('local')->assertMissing($attachment->path);

    expect($ticket->attachments()->count())->toBe(0);
});

test('the studio cannot remove a file from a ticket it is not looking at', function () {
    $onScreen = Ticket::factory()->create(['reference' => 'RSC-1206']);
    $other = Ticket::factory()->create(['reference' => 'RSC-1207']);
    $attachment = app(StoreAttachment::class)->handle($other, UploadedFile::fake()->create('theirs.pdf', 5));

    $component = Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.queue')
        ->call('select', 'RSC-1206');

    expect(fn () => $component->call('removeFile', $attachment->id))
        ->toThrow(ModelNotFoundException::class);

    expect($other->attachments()->count())->toBe(1);
});

test('a client can download a file shared with them', function () {
    $team = Team::factory()->create();
    $user = memberOf($team, ClientAccess::Full);
    $ticket = Ticket::factory()->for($team)->create();

    $attachment = app(StoreAttachment::class)->handle($ticket, UploadedFile::fake()->create('quote.pdf', 12));

    $this->actingAs($user)
        ->get(route('attachments.download', $attachment))
        ->assertOk()
        ->assertDownload('quote.pdf');
});

test('a client never gets a file the studio kept internal', function () {
    $team = Team::factory()->create();
    $user = memberOf($team, ClientAccess::Full);
    $ticket = Ticket::factory()->for($team)->create();

    $attachment = app(StoreAttachment::class)->handle(
        $ticket,
        UploadedFile::fake()->create('internal-notes.md', 2),
        sharedWithClient: false,
    );

    $this->actingAs($user)
        ->get(route('attachments.download', $attachment))
        ->assertNotFound();
});

test('a client never gets another business\'s file', function () {
    $ours = Team::factory()->create();
    $theirs = Team::factory()->create();

    $user = memberOf($ours, ClientAccess::Full);
    $ticket = Ticket::factory()->for($theirs)->create();

    $attachment = app(StoreAttachment::class)->handle($ticket, UploadedFile::fake()->create('theirs.pdf', 8));

    $this->actingAs($user)
        ->get(route('attachments.download', $attachment))
        ->assertNotFound();
});

test('the studio can download anything, internal files included', function () {
    $ticket = Ticket::factory()->create();

    $attachment = app(StoreAttachment::class)->handle(
        $ticket,
        UploadedFile::fake()->create('internal-notes.md', 2),
        sharedWithClient: false,
    );

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('attachments.download', $attachment))
        ->assertOk();
});

test('a signed out visitor gets nothing', function () {
    $ticket = Ticket::factory()->create();
    $attachment = app(StoreAttachment::class)->handle($ticket, UploadedFile::fake()->create('quote.pdf', 8));

    $this->get(route('attachments.download', $attachment))->assertRedirect(route('login'));
});

test('a row whose file has vanished off disk is a 404, not a crash', function () {
    $team = Team::factory()->create();
    $user = memberOf($team, ClientAccess::Full);
    $ticket = Ticket::factory()->for($team)->create();

    $attachment = app(StoreAttachment::class)->handle($ticket, UploadedFile::fake()->create('quote.pdf', 8));

    Storage::disk('local')->delete($attachment->path);

    $this->actingAs($user)
        ->get(route('attachments.download', $attachment))
        ->assertNotFound();
});

test('seeded rows with no file at all are a 404, not a crash', function () {
    $team = Team::factory()->create();
    $user = memberOf($team, ClientAccess::Full);
    $ticket = Ticket::factory()->for($team)->create();

    $attachment = Attachment::factory()->create([
        'attachable_type' => Ticket::class,
        'attachable_id' => $ticket->id,
        'path' => null,
    ]);

    $this->actingAs($user)
        ->get(route('attachments.download', $attachment))
        ->assertNotFound();
});

test('a refused file is explained without naming the property behind it', function () {
    $team = Team::factory()->create();
    $user = memberOf($team, ClientAccess::Full);
    Ticket::factory()->for($team)->create(['reference' => 'RSC-1208']);

    $component = Livewire::actingAs($user)
        ->test('pages::client.tickets')
        ->call('openTicket', 'RSC-1208')
        ->set('reply', UploadedFile::fake()->create('shell.php', 4))
        ->call('attachToTicket');

    $message = $component->errors()->first('reply');

    expect($message)->toContain('That file type is not supported')
        ->and($message)->not->toContain('reply');
});

test('a client sees files the studio shared on their project, but not internal ones', function () {
    $team = Team::factory()->create();
    $user = memberOf($team, ClientAccess::Full);
    $inBuild = Project::factory()->for($team)->create(['percent' => 40]);
    $finished = Project::factory()->for($team)->create(['percent' => 100, 'completed_on' => now()->subMonth()]);

    app(StoreAttachment::class)->handle($inBuild, UploadedFile::fake()->create('project-scope.pdf', 12));
    app(StoreAttachment::class)->handle($finished, UploadedFile::fake()->create('handover-notes.pdf', 12));

    $project = $inBuild;
    app(StoreAttachment::class)->handle(
        $project,
        UploadedFile::fake()->create('our-margins.md', 2),
        sharedWithClient: false,
    );

    $this->actingAs($user)
        ->get(route('client.projects'))
        ->assertOk()
        ->assertSee('project-scope.pdf')
        ->assertSee('handover-notes.pdf')
        ->assertDontSee('our-margins.md');
});

test('the advertised limit never exceeds what PHP will actually accept', function () {
    // Whatever this machine's php.ini says, we must not promise more than it
    // takes: PHP rejects an oversized upload before Laravel can explain why.
    $phpLimit = min(
        (int) round((float) ini_get('upload_max_filesize') * 1024),
        (int) round((float) ini_get('post_max_size') * 1024),
    );

    expect(Attachment::maxUploadKb(Attachment::STUDIO_MAX_KB))->toBeLessThanOrEqual($phpLimit)
        // And it never invents headroom the constants did not allow.
        ->and(Attachment::maxUploadKb(Attachment::STUDIO_MAX_KB))->toBeLessThanOrEqual(Attachment::STUDIO_MAX_KB)
        // A generous server does not lift our own ceiling either.
        ->and(Attachment::maxUploadKb(1))->toBe(1);
});
