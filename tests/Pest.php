<?php

use App\Enums\ClientAccess;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Support\Honeypot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Livewire\Features\SupportTesting\Testable;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Add the fields a real browser would send, filled in at human speed.
 *
 * The public forms refuse a submission that comes back instantly, so a test
 * posting straight to one has to look like somebody who actually typed it.
 *
 * @param  array<string, mixed>  $fields
 * @return array<string, mixed>
 */
function typedByHand(array $fields = []): array
{
    return [
        Honeypot::STAMP => Crypt::encryptString(
            (string) now()->subMinute()->getTimestamp(),
        ),
        ...$fields,
    ];
}

/**
 * Open the enquiry form and let enough time pass to have typed it in.
 *
 * The form refuses anything that comes back instantly, which no test filling
 * fields in through Livewire would otherwise wait for.
 */
function enquiryForm(): Testable
{
    $form = Livewire\Livewire::test('pages::home');

    test()->travel(Honeypot::MIN_SECONDS + 1)->seconds();

    return $form;
}

/**
 * Add a user to a client business at the given access level.
 */
function memberOf(Team $team, ClientAccess $access, TeamRole $role = TeamRole::Member): User
{
    $user = User::factory()->create();

    $team->members()->attach($user, ['role' => $role->value, 'access' => $access->value]);
    $user->switchTeam($team);

    return $user;
}
