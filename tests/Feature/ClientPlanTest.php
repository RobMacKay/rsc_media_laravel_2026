<?php

use App\Enums\ClientAccess;
use App\Models\Plan;
use App\Models\Team;
use Livewire\Livewire;

test('a client can request a different plan', function () {
    $essential = Plan::factory()->create(['name' => 'Essential', 'sort_order' => 1]);
    $partner = Plan::factory()->create(['name' => 'Partner', 'sort_order' => 2]);

    $team = Team::factory()->create(['plan_id' => $essential->id]);
    $user = memberOf($team, ClientAccess::Full);

    Livewire::actingAs($user)
        ->test('pages::client.plan')
        ->call('request', $partner->id)
        ->assertOk();

    expect($team->fresh()->requested_plan)->toBe('Partner')
        ->and($team->fresh()->plan_id)->toBe($essential->id);
});

test('requesting the plan you are already on is rejected', function () {
    $plan = Plan::factory()->create();
    $team = Team::factory()->create(['plan_id' => $plan->id]);

    Livewire::actingAs(memberOf($team, ClientAccess::Full))
        ->test('pages::client.plan')
        ->call('request', $plan->id)
        ->assertStatus(422);
});

test('people without billing access cannot change the plan', function () {
    $plan = Plan::factory()->create();
    $team = Team::factory()->create();

    Livewire::actingAs(memberOf($team, ClientAccess::Tickets))
        ->test('pages::client.plan')
        ->call('request', $plan->id)
        ->assertForbidden();

    expect($team->fresh()->requested_plan)->toBeNull();
});

test('retired plans are not offered', function () {
    Plan::factory()->create(['name' => 'Care & Support']);
    Plan::factory()->retired()->create(['name' => 'Legacy Bronze']);

    $this->actingAs(memberOf(Team::factory()->create(), ClientAccess::Full))
        ->get(route('client.plan'))
        ->assertOk()
        ->assertSee('Care &amp; Support', escape: false)
        ->assertDontSee('Legacy Bronze');
});
