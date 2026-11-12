<?php

use App\Enums\Plan;
use App\Models\Team;
use App\Models\User;

it('creates a team for a user on the Team plan and switches them to it', function () {
    $user = User::factory()->onPlan(Plan::Team)->create();

    $response = $this->actingAs($user)->post('/teams', ['name' => 'Client Co', 'slug' => 'client-co']);

    $team = Team::query()->where('slug', 'client-co')->sole();

    $response->assertRedirect(route('servers.index', $team));
    expect($team->user_id)->toBe($user->id)
        ->and($user->fresh()->current_team_id)->toBe($team->id);
});

it('refuses another team unless the user is on the Team plan', function (Plan $plan) {
    $user = User::factory()->onPlan($plan)->create();

    $this->actingAs($user)
        ->post('/teams', ['name' => 'Client Co', 'slug' => 'client-co'])
        ->assertForbidden()
        ->assertSeeText('Creating more teams needs the Team plan.');

    expect(Team::query()->where('slug', 'client-co')->exists())->toBeFalse();
})->with([Plan::Hobby, Plan::Pro]);

it('judges team creation by the user\'s own plan, not the current team\'s', function () {
    $agencyOwner = User::factory()->onPlan(Plan::Team)->create();
    $agency = Team::factory()->forUser($agencyOwner)->create();
    $member = User::factory()->onPlan(Plan::Hobby)->adminOf($agency)->create();
    $member->switchTeam($agency);

    $this->actingAs($member)
        ->post('/teams', ['name' => 'Side Project'])
        ->assertForbidden();
});
