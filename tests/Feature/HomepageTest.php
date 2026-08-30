<?php

use App\Models\Enquiry;
use App\Models\Plan;
use App\Models\User;
use App\Notifications\NewEnquiry;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

test('the homepage lists the plans on offer', function () {
    Plan::factory()->create(['name' => 'Care & Support', 'sort_order' => 1]);
    Plan::factory()->retired()->create(['name' => 'Legacy Bronze']);

    $this->get(route('home'))
        ->assertOk()
        ->assertSee('Care &amp; Support', escape: false)
        ->assertDontSee('Legacy Bronze');
});

test('an enquiry is stored and the studio is told about it', function () {
    Notification::fake();

    $ross = User::factory()->admin()->create();
    User::factory()->create();

    Livewire::test('pages::home')
        ->set('name', 'Jane Smith')
        ->set('email', 'jane@company.co.uk')
        ->set('company', 'Company Ltd')
        ->set('topic', 'existing')
        ->set('message', 'Our booking form drops the phone number.')
        ->call('send')
        ->assertHasNoErrors()
        ->assertSet('sent', true);

    $enquiry = Enquiry::sole();

    expect($enquiry->name)->toBe('Jane Smith')
        ->and($enquiry->topicLabel())->toBe('Existing system');

    Notification::assertSentTo($ross, NewEnquiry::class);
    Notification::assertCount(1);
});

test('an enquiry needs a name, an email and a message', function () {
    Livewire::test('pages::home')
        ->set('name', '')
        ->set('email', 'not-an-email')
        ->set('message', '')
        ->call('send')
        ->assertHasErrors(['name', 'email', 'message']);

    expect(Enquiry::count())->toBe(0);
});

test('an unknown topic is rejected', function () {
    Livewire::test('pages::home')
        ->set('name', 'Jane Smith')
        ->set('email', 'jane@company.co.uk')
        ->set('topic', 'something-else')
        ->set('message', 'Hello.')
        ->call('send')
        ->assertHasErrors('topic');
});
